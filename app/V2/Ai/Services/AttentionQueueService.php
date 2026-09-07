<?php

namespace App\V2\Ai\Services;

use App\Models\User;
use App\Models\V2Conversation;
use App\Models\V2Message;
use App\V2\Outreach\OutreachChannelRegistry;
use App\V2\Services\InboxUnreadService;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class AttentionQueueService
{
    public function __construct(
        private readonly InboxUnreadService $unread,
        private readonly InboxClassificationService $classifier,
    ) {}

    /**
     * @return array{
     *     items: list<array<string,mixed>>,
     *     counts: array<string,int>,
     *     summary: string,
     *     inbox_brief: array<string,mixed>
     * }
     */
    public function forUser(User $user, int $organizationId, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));

        $conversations = V2Conversation::query()
            ->where('user_id', $user->id)
            ->forUnifiedInbox()
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $unreadMap = $this->unread->unreadMap($conversations);
        $unreadConversations = $conversations->filter(fn (V2Conversation $c) => (bool) ($unreadMap[$c->id] ?? false));

        $latestMessages = V2Message::query()
            ->whereIn('conversation_id', $unreadConversations->pluck('id'))
            ->where('direction', 'inbound')
            ->orderByDesc('received_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('conversation_id')
            ->map(fn ($msgs) => $msgs->first());

        $totals = [
            'unread' => $unreadConversations->count(),
            'hot' => 0,
            'needs_judgment' => 0,
            'low_priority' => 0,
            'meeting_ready' => 0,
            'pricing_inquiry' => 0,
            'ai_can_handle' => 0,
        ];

        $classified = [];
        foreach ($unreadConversations as $conversation) {
            $message = $latestMessages[$conversation->id] ?? null;
            $body = trim((string) ($message?->body ?? ''));
            $classification = $this->classifier->classify($body);
            $priority = $classification['priority'];
            $intent = (string) ($classification['intent'] ?? '');

            $totals[$priority]++;
            if ($intent === 'meeting_request') {
                $totals['meeting_ready']++;
            }
            if ($intent === 'interested' && str_contains($body, 'price') === false && str_contains($body, 'cost') === false) {
                // pricing detected via classifier evidence too
            }
            if (in_array('pricing', $classification['evidence'] ?? [], true)) {
                $totals['pricing_inquiry']++;
            }
            if ($priority === 'low_priority') {
                $totals['ai_can_handle']++;
            }

            $classified[] = [
                'conversation' => $conversation,
                'classification' => $classification,
                'body' => $body,
            ];
        }

        usort($classified, function (array $a, array $b) {
            $order = ['hot' => 0, 'needs_judgment' => 1, 'low_priority' => 2];
            $pa = $order[$a['classification']['priority']] ?? 3;
            $pb = $order[$b['classification']['priority']] ?? 3;

            return $pa <=> $pb;
        });

        $items = [];
        foreach (array_slice($classified, 0, $limit) as $row) {
            /** @var V2Conversation $conversation */
            $conversation = $row['conversation'];
            $classification = $row['classification'];
            $meta = is_array($conversation->meta) ? $conversation->meta : [];

            $items[] = [
                'conversation_id' => $conversation->id,
                'priority' => $classification['priority'],
                'prospect_name' => Arr::get($meta, 'prospect_name') ?: 'Prospect',
                'channel' => $conversation->provider,
                'channel_label' => OutreachChannelRegistry::channelLabel((string) $conversation->provider),
                'preview' => Str::limit((string) $row['body'], 160, '…'),
                'intent' => $classification['intent'],
                'stage' => $classification['stage'],
                'recommended_action' => $classification['recommended_action'],
                'evidence' => $classification['evidence'],
                'draft_hint' => 'Use classify_reply, draft_reply, book_meeting, or set_next_best_action with conversation_id '.$conversation->id,
                'inbox_url' => url('/inbox/'.$conversation->provider.'/'.$conversation->id),
                'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            ];
        }

        $pendingCount = \App\Models\AiActionApproval::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->where('status', 'pending')
            ->count();

        $needYou = $totals['hot'] + $totals['needs_judgment'] + $pendingCount;
        $aiHandledEstimate = $totals['low_priority'];

        $counts = array_merge($totals, [
            'pending_approvals' => $pendingCount,
            'need_you' => $needYou,
            'ai_handled_estimate' => $aiHandledEstimate,
        ]);

        $inboxBrief = [
            'headline' => $totals['unread'] === 0 && $pendingCount === 0
                ? 'Inbox clear — nothing waiting on you.'
                : sprintf(
                    'AI triaged %d unread thread(s). %d need you now.',
                    $totals['unread'],
                    $needYou,
                ),
            'ai_handled_estimate' => $counts['ai_handled_estimate'],
            'need_you' => $needYou,
            'hot' => $totals['hot'],
            'needs_judgment' => $totals['needs_judgment'],
            'low_priority' => $totals['low_priority'],
            'meeting_ready' => $totals['meeting_ready'],
            'pricing_inquiry' => $totals['pricing_inquiry'],
            'pending_approvals' => $pendingCount,
        ];

        $summary = $totals['unread'] === 0 && $pendingCount === 0
            ? 'Nothing needs your attention right now.'
            : sprintf(
                'AI handled ~%d conversation(s). %d need you (%d hot, %d review, %d approvals). %d meeting-ready.',
                $counts['ai_handled_estimate'],
                $needYou,
                $totals['hot'],
                $totals['needs_judgment'],
                $pendingCount,
                $totals['meeting_ready'],
            );

        return [
            'items' => $items,
            'counts' => $counts,
            'summary' => $summary,
            'inbox_brief' => $inboxBrief,
        ];
    }
}

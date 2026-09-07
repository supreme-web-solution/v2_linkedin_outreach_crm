<?php

namespace App\V2\Ai\Services;

use App\Models\User;
use App\Models\V2OutreachCampaign;
use App\V2\Outreach\OutreachCampaignStatsService;
use App\V2\Services\UnifiedInboxReplyService;
use Illuminate\Support\Str;

class SalesManagerPlanBuilderService
{
    public function __construct(
        private readonly AttentionQueueService $attention,
        private readonly OutreachCampaignCommandService $campaigns,
        private readonly OutreachCampaignStatsService $stats,
    ) {}

    /**
     * Build a multi-step sales manager plan (pause underperformers + follow up hot inbox).
     *
     * @return array<string, mixed>
     */
    public function build(User $user, int $organizationId, int $maxPause = 3, int $maxFollowUps = 7): array
    {
        $maxPause = max(0, min(10, $maxPause));
        $maxFollowUps = max(0, min(15, $maxFollowUps));

        $actions = [];
        $steps = [];

        foreach ($this->campaignsToPause($user, $organizationId, $maxPause) as $campaign) {
            $actions[] = [
                'type' => 'pause_campaign',
                'campaign_id' => $campaign['campaign_id'],
                'campaign_name' => $campaign['campaign_name'],
                'reason' => $campaign['reason'],
            ];
            $steps[] = 'Pause campaign #'.$campaign['campaign_id'].' ('.$campaign['reason'].')';
        }

        foreach ($this->followUpActions($user, $organizationId, $maxFollowUps) as $followUp) {
            $actions[] = $followUp;
            $steps[] = 'Send follow-up to '.$followUp['prospect_name'].' ('.$followUp['channel_label'].')';
        }

        $hot = (int) ($this->attention->forUser($user, $organizationId, 1)['counts']['hot'] ?? 0);
        $needYou = (int) ($this->attention->forUser($user, $organizationId, 1)['counts']['need_you'] ?? 0);

        $goal = $actions === []
            ? 'Sales manager check-in — nothing urgent to execute right now'
            : 'Let AI execute '.$this->actionSummary($actions);

        return [
            'type' => 'sales_manager_execute',
            'goal' => $goal,
            'actions' => $actions,
            'counts' => [
                'pause_campaign' => count(array_filter($actions, fn ($a) => ($a['type'] ?? '') === 'pause_campaign')),
                'draft_reply' => count(array_filter($actions, fn ($a) => ($a['type'] ?? '') === 'draft_reply')),
                'hot_leads' => $hot,
                'need_you' => $needYou,
            ],
            'steps' => $steps !== [] ? $steps : ['No automated actions queued — review weekly brief in chat.'],
            'empty' => $actions === [],
        ];
    }

    /**
     * @return list<array{campaign_id:int, campaign_name:string, reason:string}>
     */
    private function campaignsToPause(User $user, int $organizationId, int $limit): array
    {
        if ($limit === 0) {
            return [];
        }

        $candidates = V2OutreachCampaign::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'running', 'preparing'])
            ->latest('id')
            ->limit(15)
            ->get();

        $scored = [];

        foreach ($candidates as $campaign) {
            $stats = $this->stats->statsFor($campaign);
            $errors = (int) ($stats['by_status']['error'] ?? 0);
            $failedSteps = (int) ($stats['steps_failed'] ?? 0);
            $replyRate = (float) ($stats['reply_rate'] ?? 0);
            $total = (int) ($stats['total_leads'] ?? 0);

            $score = 0;
            $reason = null;

            if ($errors >= 3 || $failedSteps >= 5) {
                $score = 100 + $errors;
                $reason = "{$errors} delivery error(s), {$failedSteps} failed step(s)";
            } elseif ($total >= 30 && $replyRate < 1.5) {
                $score = 50;
                $reason = "Low reply rate ({$replyRate}%) on {$total} leads";
            }

            if ($score > 0 && $reason !== null) {
                $scored[] = [
                    'score' => $score,
                    'campaign_id' => $campaign->id,
                    'campaign_name' => $campaign->name,
                    'reason' => $reason,
                ];
            }
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $limit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function followUpActions(User $user, int $organizationId, int $limit): array
    {
        if ($limit === 0) {
            return [];
        }

        $queue = $this->attention->forUser($user, $organizationId, $limit + 5);
        $replyService = app(UnifiedInboxReplyService::class);
        $actions = [];

        foreach ($queue['items'] as $item) {
            if (count($actions) >= $limit) {
                break;
            }

            if (! in_array($item['priority'] ?? '', ['hot', 'needs_judgment'], true)) {
                continue;
            }

            $conversationId = (int) ($item['conversation_id'] ?? 0);
            if ($conversationId <= 0) {
                continue;
            }

            try {
                $conversation = \App\Models\V2Conversation::query()
                    ->where('user_id', $user->id)
                    ->whereKey($conversationId)
                    ->firstOrFail();

                $draft = $replyService->draftReplyForConversation($user, $conversation);
            } catch (\Throwable) {
                continue;
            }

            $actions[] = [
                'type' => 'draft_reply',
                'conversation_id' => $conversationId,
                'prospect_name' => $draft['prospect_name'],
                'channel' => $draft['channel'],
                'channel_label' => $draft['channel_label'],
                'inbound_preview' => Str::limit($draft['inbound_preview'], 200, '…'),
                'draft_text' => $draft['draft'],
                'inbox_url' => $draft['inbox_url'],
                'priority' => $item['priority'],
                'intent' => $item['intent'] ?? null,
            ];
        }

        return $actions;
    }

    /**
     * @param  list<array<string, mixed>>  $actions
     */
    private function actionSummary(array $actions): string
    {
        $pause = count(array_filter($actions, fn ($a) => ($a['type'] ?? '') === 'pause_campaign'));
        $replies = count(array_filter($actions, fn ($a) => ($a['type'] ?? '') === 'draft_reply'));

        $parts = [];
        if ($pause > 0) {
            $parts[] = "pause {$pause} campaign(s)";
        }
        if ($replies > 0) {
            $parts[] = "follow up {$replies} conversation(s)";
        }

        return implode(' + ', $parts);
    }
}

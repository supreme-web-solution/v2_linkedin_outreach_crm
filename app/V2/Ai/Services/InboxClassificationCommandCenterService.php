<?php

namespace App\V2\Ai\Services;

use App\Models\User;
use App\Models\V2Conversation;
use App\Models\V2Message;
use App\V2\Outreach\OutreachChannelRegistry;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class InboxClassificationCommandCenterService
{
    public function __construct(
        private readonly InboxClassificationService $classifier,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function classifyConversation(User $user, int $conversationId): array
    {
        $conversation = V2Conversation::query()
            ->where('user_id', $user->id)
            ->whereKey($conversationId)
            ->first();

        if (! $conversation) {
            throw new \RuntimeException('Conversation not found.');
        }

        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $latestInbound = V2Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'inbound')
            ->orderByDesc('received_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        $body = trim((string) ($latestInbound?->body ?? ''));
        $classification = $this->classifier->classify($body);

        return [
            'conversation_id' => $conversation->id,
            'prospect_name' => Arr::get($meta, 'prospect_name') ?: 'Prospect',
            'channel' => $conversation->provider,
            'channel_label' => OutreachChannelRegistry::channelLabel((string) $conversation->provider),
            'inbound_preview' => Str::limit($body, 200, '…'),
            'priority_label' => $this->classifier->priorityLabel($classification['priority']),
            'inbox_url' => url('/inbox/'.$conversation->provider.'/'.$conversation->id),
            'suggested_tools' => $this->suggestedTools($classification),
            ...$classification,
        ];
    }

    /**
     * @param  array<string, mixed>  $classification
     * @return list<string>
     */
    private function suggestedTools(array $classification): array
    {
        $tools = ['draft_reply', 'set_next_best_action'];

        if (($classification['priority'] ?? '') === 'hot') {
            array_unshift($tools, 'draft_personalized_message');
        }

        if (($classification['intent'] ?? '') === 'opt_out') {
            return ['set_next_best_action', 'pause_outreach_campaign'];
        }

        return $tools;
    }
}

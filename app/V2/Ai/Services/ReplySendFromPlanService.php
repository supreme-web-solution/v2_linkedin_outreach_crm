<?php

namespace App\V2\Ai\Services;

use App\Models\AiActionApproval;
use App\Models\User;
use App\Models\V2Conversation;
use App\V2\Ai\Support\RecipientFacingCopyGuard;
use App\V2\Services\UnifiedInboxReplyService;

class ReplySendFromPlanService
{
    /**
     * @return array{message: string, inbox_url: string, message_id: int|null}
     */
    public function sendFromApproval(AiActionApproval $approval, User $user): array
    {
        $existingMessageId = data_get($approval->result, 'v2_message_id');
        if ($existingMessageId) {
            return [
                'message' => 'Reply already sent for this approval.',
                'inbox_url' => (string) data_get($approval->payload, 'inbox_url', url('/inbox')),
                'message_id' => (int) $existingMessageId,
            ];
        }

        $payload = $approval->payload ?? [];
        $conversationId = (int) ($payload['conversation_id'] ?? 0);
        $draft = trim((string) ($payload['draft_text'] ?? $payload['draft'] ?? ''));

        if ($conversationId <= 0 || $draft === '') {
            throw new \InvalidArgumentException('Missing conversation or draft text in reply plan.');
        }

        RecipientFacingCopyGuard::assertSendable($draft);

        $conversation = V2Conversation::query()
            ->where('user_id', $user->id)
            ->whereKey($conversationId)
            ->firstOrFail();

        $sent = app(UnifiedInboxReplyService::class)->sendApprovedReply($user, $conversation, $draft);

        $approval->update([
            'result' => [
                'v2_message_id' => $sent->id,
                'conversation_id' => $conversation->id,
                'status' => 'reply_sent',
            ],
            'status' => 'executed',
        ]);

        return [
            'message' => 'Reply sent to '.($payload['prospect_name'] ?? 'prospect').'.',
            'inbox_url' => (string) ($payload['inbox_url'] ?? url('/inbox/'.$conversation->provider.'/'.$conversation->id)),
            'message_id' => $sent->id,
        ];
    }
}

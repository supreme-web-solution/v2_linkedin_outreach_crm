<?php

namespace App\V2\Ai\Services;

use App\Models\AiActionApproval;
use App\Models\User;
use App\V2\Ai\Support\RecipientFacingCopyGuard;
use App\V2\Services\InboxConversationResolverService;
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
        $draft = trim((string) ($payload['draft_text'] ?? $payload['draft'] ?? ''));

        if ($draft === '') {
            throw new \InvalidArgumentException('Missing draft text in reply plan.');
        }

        $draft = RecipientFacingCopyGuard::prepareOutbound($draft, [
            'user' => $user,
            'organization_id' => (int) ($user->current_organization_id ?? 0),
        ]);
        RecipientFacingCopyGuard::assertSendable($draft);

        $conversation = app(InboxConversationResolverService::class)
            ->resolveForApproval($approval, $user);

        // Keep payload pointed at the live thread if it was remapped after reconnect.
        if ((int) ($payload['conversation_id'] ?? 0) !== (int) $conversation->id) {
            $payload['conversation_id'] = $conversation->id;
            $payload['provider_chat_id'] = $conversation->provider_chat_id;
            $payload['inbox_url'] = url('/inbox/'.$conversation->provider.'/'.$conversation->id);
            $approval->update(['payload' => $payload]);
        }

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

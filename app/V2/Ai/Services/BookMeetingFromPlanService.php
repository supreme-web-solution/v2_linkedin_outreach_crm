<?php

namespace App\V2\Ai\Services;

use App\Models\AiActionApproval;
use App\Models\User;
use App\Models\V2Conversation;
use App\V2\Services\UnifiedInboxReplyService;

class BookMeetingFromPlanService
{
    /**
     * @return array{message:string, inbox_url:string, call_id:int, booking_url:string}
     */
    public function applyFromApproval(AiActionApproval $approval, User $user): array
    {
        $existingCallId = (int) data_get($approval->result, 'call_id', 0);
        if ($existingCallId > 0 && data_get($approval->result, 'status') === 'booking_sent') {
            return [
                'message' => 'Booking link already sent for this approval.',
                'inbox_url' => (string) data_get($approval->payload, 'inbox_url', url('/inbox')),
                'call_id' => $existingCallId,
                'booking_url' => (string) data_get($approval->payload, 'booking_url', ''),
            ];
        }

        $payload = $approval->payload ?? [];
        $conversationId = (int) ($payload['conversation_id'] ?? 0);
        $draft = trim((string) ($payload['draft_text'] ?? ''));
        $callId = (int) ($payload['call_id'] ?? 0);

        if ($conversationId <= 0 || $draft === '') {
            throw new \InvalidArgumentException('Missing conversation or booking message.');
        }

        $conversation = V2Conversation::query()
            ->where('user_id', $user->id)
            ->whereKey($conversationId)
            ->firstOrFail();

        $sent = app(UnifiedInboxReplyService::class)->sendApprovedReply($user, $conversation, $draft);

        $approval->update([
            'result' => [
                'call_id' => $callId,
                'v2_message_id' => $sent->id,
                'conversation_id' => $conversation->id,
                'status' => 'booking_sent',
            ],
            'status' => 'executed',
        ]);

        return [
            'message' => 'Booking link sent to '.($payload['prospect_name'] ?? 'prospect').'.',
            'inbox_url' => (string) ($payload['inbox_url'] ?? url('/inbox/'.$conversation->provider.'/'.$conversation->id)),
            'call_id' => $callId,
            'booking_url' => (string) ($payload['booking_url'] ?? ''),
        ];
    }
}

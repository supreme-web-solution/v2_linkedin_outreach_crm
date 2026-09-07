<?php

namespace App\V2\Ai\Services;

use App\Models\AiActionApproval;
use App\Models\AiConversation;
use App\Models\User;
use App\Models\V2Conversation;
use App\V2\Ai\Enums\AiAutonomyLevel;
use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Services\UnifiedInboxReplyService;

class InboxCommandCenterService
{
    public function __construct(
        private readonly AttentionQueueService $attention,
        private readonly NurtureQueueService $nurture,
        private readonly ActionApprovalService $approvals,
        private readonly CommandCenterService $commandCenter,
        private readonly AiEmployeeSettingsService $settingsService,
    ) {}

    /**
     * @return array{
     *     items: list<array<string,mixed>>,
     *     counts: array<string,int>,
     *     summary: string,
     *     inbox_brief?: array<string,mixed>,
     *     nurture_brief?: array<string,mixed>
     * }
     */
    public function attention(User $user, int $organizationId, int $limit = 10): array
    {
        $result = $this->attention->forUser($user, $organizationId, $limit);
        $result['nurture_brief'] = $this->nurture->briefForUser($user);

        return $result;
    }

    /**
     * @return array{
     *     items: list<array<string,mixed>>,
     *     counts: array<string,int>,
     *     summary: string,
     *     nurture_brief: array<string,mixed>
     * }
     */
    public function nurtureQueue(User $user, int $limit = 50): array
    {
        return $this->nurture->forUser($user, $limit);
    }

    /**
     * Stage a draft inbox reply as a Command Center approval.
     *
     * @return array{
     *     approval: AiActionApproval|null,
     *     plan: array<string,mixed>,
     *     card: string,
     *     approval_id: int|null,
     *     blocked?: bool,
     *     message?: string
     * }
     */
    public function stageDraftReply(
        User $user,
        int $organizationId,
        AiConversation $conversation,
        int $v2ConversationId,
        ?string $notes = null,
        string $surface = 'web',
    ): array {
        $settings = $this->settingsService->for($user, $organizationId);

        if ($this->settingsService->isBlocked($settings)) {
            return [
                'approval' => null,
                'approval_id' => null,
                'plan' => [],
                'card' => '',
                'blocked' => true,
                'message' => 'AI Employee is currently disabled for this workspace.',
            ];
        }

        $v2Conversation = V2Conversation::query()
            ->where('user_id', $user->id)
            ->whereKey($v2ConversationId)
            ->first();

        if (! $v2Conversation) {
            throw new \RuntimeException('Conversation not found.');
        }

        $draftData = app(UnifiedInboxReplyService::class)->draftReplyForConversation($user, $v2Conversation);
        $notes = trim((string) $notes);

        $plan = [
            'type' => 'draft_reply',
            'goal' => 'Send inbox reply to '.$draftData['prospect_name'],
            'conversation_id' => $v2ConversationId,
            'prospect_name' => $draftData['prospect_name'],
            'channel' => $draftData['channel'],
            'channel_label' => $draftData['channel_label'],
            'inbound_preview' => $draftData['inbound_preview'],
            'draft_text' => $draftData['draft'],
            'agent_notes' => $notes !== '' ? $notes : null,
            'inbox_url' => $draftData['inbox_url'],
            'status' => 'awaiting_review',
        ];

        $autonomy = AiAutonomyLevel::tryFrom((int) $settings->autonomy_level) ?? AiAutonomyLevel::Assisted;
        $card = $this->commandCenter->formatPlanCard($plan, null, $surface);

        if ($autonomy->value <= AiAutonomyLevel::Copilot->value) {
            return [
                'approval' => null,
                'approval_id' => null,
                'plan' => $plan,
                'card' => $card,
                'message' => 'Copilot mode: draft shown for review only.',
            ];
        }

        $approval = $this->approvals->createPending(
            $user,
            $organizationId,
            'draft_reply',
            AiToolPermission::Prepare,
            $plan,
            $conversation,
        );

        $card = $this->commandCenter->formatPlanCard($plan, $approval->id, $surface);

        if ($autonomy->value >= AiAutonomyLevel::Autopilot->value) {
            $auto = $this->tryAutoSendReply($approval, $user, $plan, $surface);
            if ($auto !== null) {
                return $auto;
            }
        }

        return [
            'approval' => $approval,
            'approval_id' => $approval->id,
            'plan' => $plan,
            'card' => $card,
        ];
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>|null
     */
    private function tryAutoSendReply(
        AiActionApproval $approval,
        User $user,
        array $plan,
        string $surface,
    ): ?array {
        try {
            $result = app(ReplySendFromPlanService::class)->sendFromApproval($approval, $user);

            return [
                'approval' => $approval->fresh(),
                'approval_id' => $approval->id,
                'plan' => $plan,
                'card' => $this->commandCenter->formatPlanCard($plan, null, $surface),
                'auto_sent' => true,
                'message' => $result['message'],
            ];
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * @return array{message:string,inbox_url:string,message_id:int|null}
     */
    public function sendReplyNow(User $user, int $organizationId, int $v2ConversationId, string $message): array
    {
        $v2Conversation = V2Conversation::query()
            ->where('user_id', $user->id)
            ->whereKey($v2ConversationId)
            ->firstOrFail();

        $sent = app(UnifiedInboxReplyService::class)->sendApprovedReply($user, $v2Conversation, trim($message));

        return [
            'message' => 'Reply sent.',
            'inbox_url' => url('/inbox/'.$v2Conversation->provider.'/'.$v2Conversation->id),
            'message_id' => $sent->id,
        ];
    }

    public function updateDraftText(AiActionApproval $approval, string $draftText): AiActionApproval
    {
        $payload = $approval->payload ?? [];
        $payload['draft_text'] = trim($draftText);
        $approval->update(['payload' => $payload]);

        return $approval->refresh();
    }

    /**
     * @return array{
     *     approval: AiActionApproval|null,
     *     plan: array<string,mixed>,
     *     card: string,
     *     approval_id: int|null,
     *     blocked?: bool,
     *     message?: string
     * }
     */
    public function stageNextBestAction(
        User $user,
        int $organizationId,
        AiConversation $conversation,
        int $v2ConversationId,
        ?string $action = null,
        ?string $reason = null,
        string $surface = 'web',
    ): array {
        return app(NextBestActionCommandCenterService::class)->stage(
            $user,
            $organizationId,
            $conversation,
            null,
            $v2ConversationId,
            trim((string) ($action ?? '')),
            $reason,
            $surface,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function classifyConversation(User $user, int $v2ConversationId): array
    {
        return app(InboxClassificationCommandCenterService::class)->classifyConversation(
            $user,
            $v2ConversationId,
        );
    }
}

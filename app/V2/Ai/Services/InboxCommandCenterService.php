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
        private readonly ActionApprovalService $approvals,
        private readonly CommandCenterService $commandCenter,
        private readonly AiEmployeeSettingsService $settingsService,
    ) {}

    /**
     * @return array{
     *     items: list<array<string,mixed>>,
     *     counts: array<string,int>,
     *     summary: string
     * }
     */
    public function attention(User $user, int $organizationId, int $limit = 10): array
    {
        return $this->attention->forUser($user, $organizationId, $limit);
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

        return [
            'approval' => $approval,
            'approval_id' => $approval->id,
            'plan' => $plan,
            'card' => $card,
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

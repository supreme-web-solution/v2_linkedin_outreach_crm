<?php

namespace App\V2\Ai\Services;

use App\Models\AiActionApproval;
use App\Models\AiConversation;
use App\Models\User;
use App\V2\Ai\Enums\AiAutonomyLevel;
use App\V2\Ai\Enums\AiToolPermission;

class ExecuteSalesPlanCommandCenterService
{
    public function __construct(
        private readonly SalesManagerPlanBuilderService $builder,
        private readonly ActionApprovalService $approvals,
        private readonly CommandCenterService $commandCenter,
        private readonly AiEmployeeSettingsService $settingsService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function stage(
        User $user,
        int $organizationId,
        AiConversation $conversation,
        string $surface = 'web',
        ?int $maxPause = null,
        ?int $maxFollowUps = null,
        ?int $maxNurture = null,
        ?int $maxScale = null,
        ?int $maxActivate = null,
    ): array {
        $settings = $this->settingsService->for($user, $organizationId);

        if ($this->settingsService->isBlocked($settings)) {
            return [
                'blocked' => true,
                'message' => 'AI Employee is currently disabled for this workspace.',
            ];
        }

        $plan = $this->builder->build(
            $user,
            $organizationId,
            $maxPause ?? 3,
            $maxFollowUps ?? 7,
            $maxNurture ?? 3,
            $maxScale ?? 2,
            $maxActivate ?? 2,
        );

        if ($plan['empty'] ?? false) {
            return [
                'blocked' => false,
                'empty' => true,
                'plan' => $plan,
                'card' => "Nothing urgent to execute right now.\n\n"
                    ."• Inbox looks clear or low priority\n"
                    ."• No campaigns flagged for pause\n\n"
                    .'Say "weekly brief" or "attention" for a manual review.',
            ];
        }

        $plan['status'] = 'awaiting_review';

        $autonomy = AiAutonomyLevel::tryFrom((int) $settings->autonomy_level) ?? AiAutonomyLevel::Assisted;

        if ($autonomy->value <= AiAutonomyLevel::Copilot->value) {
            return [
                'approval_id' => null,
                'plan' => $plan,
                'card' => $this->commandCenter->formatPlanCard($plan, null, $surface),
                'message' => 'Copilot mode: recommendations only — switch to Assisted to stage Launch.',
            ];
        }

        /** @var AiActionApproval $approval */
        $approval = $this->approvals->createPending(
            $user,
            $organizationId,
            'let_ai_execute',
            AiToolPermission::Prepare,
            $plan,
            $conversation,
        );

        return [
            'approval_id' => $approval->id,
            'plan' => $plan,
            'card' => $this->commandCenter->formatPlanCard($plan, $approval->id, $surface),
        ];
    }
}

<?php

namespace App\V2\Ai\Services;

use App\Models\User;
use App\Models\V2Call;
use App\V2\Ai\Enums\AiAutonomyLevel;
use App\V2\Ai\Enums\AiToolPermission;
use Illuminate\Support\Str;

class PostCallCrmCommandCenterService
{
    public function __construct(
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
        \App\Models\AiConversation $conversation,
        int $callId,
        string $outcome,
        ?string $summary,
        ?string $nextSteps,
        string $surface = 'web',
    ): array {
        $settings = $this->settingsService->for($user, $organizationId);

        if ($this->settingsService->isBlocked($settings)) {
            return ['blocked' => true, 'message' => 'AI Employee is currently disabled for this workspace.'];
        }

        $call = V2Call::query()
            ->whereKey($callId)
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->first();

        if (! $call) {
            throw new \RuntimeException("Call #{$callId} not found.");
        }

        $outcome = Str::snake(trim($outcome));
        $allowed = ['completed', 'follow_up', 'not_interested', 'no_show', 'rescheduled', 'qualified', 'customer'];
        if (! in_array($outcome, $allowed, true)) {
            throw new \RuntimeException('outcome must be one of: '.implode(', ', $allowed));
        }

        $plan = [
            'type' => 'post_call_crm',
            'goal' => 'Update CRM after call with '.($call->prospect_name ?? 'prospect'),
            'call_id' => $call->id,
            'prospect_name' => $call->prospect_name,
            'outcome' => $outcome,
            'summary' => trim((string) $summary) !== '' ? trim((string) $summary) : null,
            'next_steps' => trim((string) $nextSteps) !== '' ? trim((string) $nextSteps) : null,
            'crm_notes' => trim((string) ($summary ?? '')) !== '' ? trim((string) $summary) : null,
            'call_url' => url('/calls/'.$call->id),
            'status' => 'awaiting_review',
        ];

        $autonomy = AiAutonomyLevel::tryFrom((int) $settings->autonomy_level) ?? AiAutonomyLevel::Assisted;
        $card = $this->commandCenter->formatPlanCard($plan, null, $surface);

        if ($autonomy->value <= AiAutonomyLevel::Copilot->value) {
            return [
                'approval_id' => null,
                'plan' => $plan,
                'card' => $card,
                'message' => 'Copilot mode: recommendation only.',
            ];
        }

        $approval = $this->approvals->createPending(
            $user,
            $organizationId,
            'post_call_crm_update',
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

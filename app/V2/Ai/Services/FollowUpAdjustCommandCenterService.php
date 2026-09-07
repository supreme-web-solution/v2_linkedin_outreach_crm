<?php

namespace App\V2\Ai\Services;

use App\Models\AiActionApproval;
use App\Models\AiConversation;
use App\Models\User;
use App\V2\Ai\Enums\AiAutonomyLevel;
use App\V2\Ai\Enums\AiToolPermission;
use Illuminate\Support\Str;

class FollowUpAdjustCommandCenterService
{
    public function __construct(
        private readonly OutreachCampaignCommandService $campaigns,
        private readonly ActionApprovalService $approvals,
        private readonly CommandCenterService $commandCenter,
        private readonly AiEmployeeSettingsService $settingsService,
    ) {}

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
    public function stageAdjustment(
        User $user,
        int $organizationId,
        AiConversation $conversation,
        int $campaignId,
        string $adjustment,
        ?int $deltaDays,
        ?string $reason,
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

        $campaign = $this->campaigns->findOwned($user, $organizationId, $campaignId);
        if (! $campaign) {
            throw new \RuntimeException("Outreach campaign #{$campaignId} not found.");
        }

        $adjustment = Str::snake(trim($adjustment));
        $allowed = ['extend_waits', 'shorten_waits', 'pause_on_reply'];
        if (! in_array($adjustment, $allowed, true)) {
            throw new \RuntimeException('adjustment must be extend_waits, shorten_waits, or pause_on_reply.');
        }

        $deltaDays = max(1, min(14, (int) ($deltaDays ?? 1)));

        $steps = match ($adjustment) {
            'extend_waits' => [
                "Add {$deltaDays} day(s) to each wait step in campaign #{$campaignId}.",
                'Active leads keep their progress; future waits use the updated timing.',
            ],
            'shorten_waits' => [
                "Reduce each wait step by up to {$deltaDays} day(s) in campaign #{$campaignId} (minimum 1 day).",
                'Review copy before activating if prospects are mid-sequence.',
            ],
            'pause_on_reply' => [
                'Flag campaign to pause individual leads when they reply (recommended for high-touch sequences).',
                'Confirm pause-on-reply behavior in the outreach builder if not already enabled.',
            ],
        };

        $plan = [
            'type' => 'follow_up_adjustment',
            'goal' => 'Adjust follow-up timing for '.$campaign->name,
            'campaign_id' => $campaign->id,
            'campaign_name' => $campaign->name,
            'adjustment' => $adjustment,
            'delta_days' => $adjustment === 'pause_on_reply' ? null : $deltaDays,
            'reason' => trim((string) $reason) !== '' ? trim((string) $reason) : null,
            'steps' => $steps,
            'outreach_url' => url('/outreach/'.$campaign->id),
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
                'message' => 'Copilot mode: recommendation only.',
            ];
        }

        $approval = $this->approvals->createPending(
            $user,
            $organizationId,
            'adjust_follow_up',
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
}

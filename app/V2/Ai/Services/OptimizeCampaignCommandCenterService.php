<?php

namespace App\V2\Ai\Services;

use App\Models\AiActionApproval;
use App\Models\AiConversation;
use App\Models\User;
use App\V2\Ai\Enums\AiAutonomyLevel;
use App\V2\Ai\Enums\AiToolPermission;

class OptimizeCampaignCommandCenterService
{
    public function __construct(
        private readonly OutreachCampaignCommandService $campaigns,
        private readonly CampaignOptimizerService $optimizer,
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
    public function stage(
        User $user,
        int $organizationId,
        AiConversation $conversation,
        int $campaignId,
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

        $analysis = $this->optimizer->analyze($campaign);
        $steps = array_map(
            fn (array $s) => '['.$s['priority'].'] '.$s['title'].' — '.$s['detail'],
            $analysis['suggestions'],
        );

        $plan = [
            'type' => 'campaign_optimization',
            'goal' => 'Optimize '.$campaign->name,
            'campaign_id' => $campaign->id,
            'campaign_name' => $campaign->name,
            'metrics' => $analysis['metrics'],
            'suggestions' => $analysis['suggestions'],
            'steps' => $steps !== [] ? $steps : ['No major issues detected — monitor reply rate weekly.'],
            'outreach_url' => $analysis['outreach_url'],
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
                'message' => 'Copilot mode: recommendations only.',
            ];
        }

        $approval = $this->approvals->createPending(
            $user,
            $organizationId,
            'optimize_campaign',
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
            'analysis' => $analysis,
        ];
    }
}

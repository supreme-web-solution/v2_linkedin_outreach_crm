<?php

namespace App\V2\Ai\Services;

use App\Jobs\V2\CleanupDeletedCampaignArtifactsJob;
use App\Models\AiActionApproval;
use App\Models\AiConversation;
use App\Models\User;
use App\Models\V2Campaign;
use App\Models\V2CampaignLeadProgress;
use App\Models\V2CampaignRun;
use App\Models\V2OutreachLeadProgress;
use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Support\DeletedCampaignArtifactCleaner;
use Illuminate\Support\Str;

/**
 * Destructive deletes always stage for Review & Launch — never auto-run, any autonomy level.
 */
class DeleteCampaignCommandCenterService
{
    public function __construct(
        private readonly OutreachCampaignCommandService $outreach,
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
        string $kind,
        int $campaignId,
        ?string $reason = null,
        string $surface = 'web',
    ): array {
        $settings = $this->settingsService->for($user, $organizationId);
        if ($this->settingsService->isBlocked($settings)) {
            return [
                'blocked' => true,
                'message' => 'AI Employee is currently disabled for this workspace.',
            ];
        }

        $kind = Str::lower(trim($kind));
        if (! in_array($kind, ['outreach', 'linkedin'], true)) {
            throw new \InvalidArgumentException('kind must be outreach or linkedin.');
        }

        if ($kind === 'outreach') {
            $campaign = $this->outreach->findOwned($user, $organizationId, $campaignId);
            if (! $campaign) {
                throw new \RuntimeException("Outreach campaign #{$campaignId} not found.");
            }
            $name = (string) $campaign->name;
            $status = (string) $campaign->status;
            $leads = (int) $campaign->outreachLeads()->count();
            $url = url('/outreach/'.$campaign->id);
        } else {
            $campaign = V2Campaign::query()
                ->where('id', $campaignId)
                ->where('organization_id', $organizationId)
                ->where('user_id', $user->id)
                ->first();
            if (! $campaign) {
                throw new \RuntimeException("LinkedIn campaign #{$campaignId} not found.");
            }
            $name = (string) $campaign->name;
            $status = (string) $campaign->status;
            $leads = (int) $campaign->campaignLeads()->count();
            $url = url('/campaigns/'.$campaign->id);
        }

        $plan = [
            'type' => 'campaign_delete',
            'kind' => $kind,
            'campaign_id' => $campaignId,
            'campaign_name' => $name,
            'campaign_status' => $status,
            'leads_count' => $leads,
            'reason' => $reason,
            'goal' => 'Delete '.($kind === 'outreach' ? 'outreach' : 'LinkedIn')." campaign #{$campaignId}",
            'steps' => [
                "Permanently delete \"{$name}\" (#{$campaignId}).",
                $leads > 0 ? "This removes {$leads} lead assignment(s) from the campaign." : 'No lead rows attached.',
                'This cannot be undone from Command Center — confirm carefully.',
            ],
            'destructive' => true,
            'requires_explicit_approval' => true,
            'status' => 'awaiting_review',
            'campaign_url' => $url,
        ];

        // Always stage — never auto-delete, including Autopilot / Autonomous.
        $approval = $this->approvals->createPending(
            $user,
            $organizationId,
            'delete_campaign',
            AiToolPermission::Prepare,
            $plan,
            $conversation,
        );

        return [
            'approval_id' => $approval->id,
            'plan' => $plan,
            'card' => $this->commandCenter->formatPlanCard($plan, $approval->id, $surface),
            'cta' => 'Confirm Delete in Review & Launch (or LAUNCH '.$approval->id.' on WhatsApp). Deletes never auto-run.',
        ];
    }

    /**
     * @return array{message:string, campaign_id:int, kind:string}
     */
    public function applyFromApproval(AiActionApproval $approval, User $user): array
    {
        $payload = $approval->payload ?? [];
        $kind = Str::lower((string) ($payload['kind'] ?? 'outreach'));
        $campaignId = (int) ($payload['campaign_id'] ?? 0);
        $orgId = (int) $approval->organization_id;

        if ($campaignId <= 0) {
            throw new \RuntimeException('Missing campaign_id on delete plan.');
        }

        if ($kind === 'linkedin') {
            return $this->deleteLinkedInCampaign($user, $orgId, $campaignId);
        }

        return $this->deleteOutreachCampaign($user, $orgId, $campaignId);
    }

    /**
     * @return array{message:string, campaign_id:int, kind:string}
     */
    private function deleteOutreachCampaign(User $user, int $organizationId, int $campaignId): array
    {
        $campaign = $this->outreach->findOwned($user, $organizationId, $campaignId);
        if (! $campaign) {
            throw new \RuntimeException("Outreach campaign #{$campaignId} not found (may already be deleted).");
        }

        $name = (string) $campaign->name;
        $userId = (int) $campaign->user_id;

        if (in_array($campaign->status, ['active', 'running', 'preparing'], true)) {
            $campaign->update(['status' => 'stopped']);
        }

        V2OutreachLeadProgress::where('outreach_campaign_id', $campaignId)->delete();
        $campaign->outreachLeads()->delete();
        $campaign->outreachLists()->delete();
        $campaign->delete();

        CleanupDeletedCampaignArtifactsJob::dispatch(
            DeletedCampaignArtifactCleaner::KIND_OUTREACH,
            $campaignId,
            $userId,
        );

        return [
            'message' => "Deleted outreach campaign #{$campaignId}: {$name}.",
            'campaign_id' => $campaignId,
            'kind' => 'outreach',
        ];
    }

    /**
     * @return array{message:string, campaign_id:int, kind:string}
     */
    private function deleteLinkedInCampaign(User $user, int $organizationId, int $campaignId): array
    {
        $campaign = V2Campaign::query()
            ->where('id', $campaignId)
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->first();

        if (! $campaign) {
            throw new \RuntimeException("LinkedIn campaign #{$campaignId} not found (may already be deleted).");
        }

        $name = (string) $campaign->name;
        $userId = (int) $campaign->user_id;

        if (in_array($campaign->status, ['active', 'running', 'preparing'], true)) {
            $campaign->update(['status' => 'stopped']);
        }

        $runIds = V2CampaignRun::query()
            ->where('legacy_campaign_id', $campaignId)
            ->pluck('id')
            ->map(fn ($runId) => (int) $runId)
            ->all();

        V2CampaignLeadProgress::where('campaign_id', $campaignId)->delete();
        $campaign->campaignLeads()->delete();
        $campaign->campaignLists()->delete();
        $campaign->delete();

        CleanupDeletedCampaignArtifactsJob::dispatch(
            DeletedCampaignArtifactCleaner::KIND_CAMPAIGN,
            $campaignId,
            $userId,
            $runIds,
        );

        return [
            'message' => "Deleted LinkedIn campaign #{$campaignId}: {$name}.",
            'campaign_id' => $campaignId,
            'kind' => 'linkedin',
        ];
    }
}

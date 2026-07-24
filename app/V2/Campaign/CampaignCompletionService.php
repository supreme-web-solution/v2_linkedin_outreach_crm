<?php

namespace App\V2\Campaign;

use App\Models\V2Campaign;
use App\Models\V2CampaignLead;
use App\Models\V2CampaignRun;
use Illuminate\Support\Facades\Log;

class CampaignCompletionService
{
    public function __construct(
        private readonly CampaignActivityLogger $logger = new CampaignActivityLogger(),
    ) {}

    /**
     * Mark the campaign completed when every lead has reached a terminal state.
     */
    public function maybeFinish(V2Campaign $campaign, ?V2CampaignRun $run = null): bool
    {
        if (!in_array($campaign->status, ['active', 'running'], true)) {
            return false;
        }

        $totalLeads = V2CampaignLead::query()
            ->where('campaign_id', $campaign->id)
            ->count();

        if ($totalLeads === 0) {
            return false;
        }

        $activeLeads = V2CampaignLead::query()
            ->where('campaign_id', $campaign->id)
            ->whereIn('status', ['pending', 'running'])
            ->count();

        if ($activeLeads > 0) {
            return false;
        }

        $campaign->forceFill(['status' => 'completed'])->save();

        $runToComplete = $run ?? V2CampaignRun::query()
            ->where('legacy_campaign_id', $campaign->id)
            ->whereIn('status', ['queued', 'running'])
            ->latest('id')
            ->first();

        if ($runToComplete) {
            $runToComplete->forceFill([
                'status' => 'completed',
                'completed_at' => now(),
                'current_step_key' => 'completed',
            ])->save();
        }

        Log::info('[Campaign] Campaign marked completed — all leads finished', [
            'campaign_id' => $campaign->id,
            'run_id' => $runToComplete?->id,
            'total_leads' => $totalLeads,
        ]);

        $this->logger->log(
            $campaign->id,
            null,
            $runToComplete?->id,
            null,
            'completed',
            "Campaign \"{$campaign->name}\" completed — all leads finished.",
        );

        return true;
    }
}

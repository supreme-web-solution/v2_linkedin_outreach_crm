<?php

namespace App\V2\Outreach;

use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLead;
use App\Models\V2OutreachRun;
use Illuminate\Support\Facades\Log;

class OutreachCompletionService
{
    public function __construct(
        private readonly OutreachActivityLogger $logger = new OutreachActivityLogger(),
    ) {}

    /**
     * Mark the campaign completed when every lead has reached a terminal state
     * (done / error / skipped / replied — anything except pending/running).
     * Works for email, LinkedIn, WhatsApp, Instagram, Telegram, X — any channel/node.
     */
    public function maybeFinish(V2OutreachCampaign $campaign, ?V2OutreachRun $run = null): bool
    {
        if (! in_array($campaign->status, ['active', 'running'], true)) {
            return false;
        }

        $totalLeads = V2OutreachLead::query()
            ->where('outreach_campaign_id', $campaign->id)
            ->count();

        if ($totalLeads === 0) {
            return false;
        }

        $activeLeads = V2OutreachLead::query()
            ->where('outreach_campaign_id', $campaign->id)
            ->whereIn('status', ['pending', 'running'])
            ->count();

        if ($activeLeads > 0) {
            return false;
        }

        $campaign->forceFill(['status' => 'completed'])->save();

        $runToComplete = $run ?? V2OutreachRun::query()
            ->where('outreach_campaign_id', $campaign->id)
            ->whereIn('status', ['queued', 'running'])
            ->latest('id')
            ->first();

        if ($runToComplete) {
            $runToComplete->forceFill([
                'status' => 'completed',
                'finished_at' => now(),
            ])->save();
        }

        Log::info('[Outreach] Campaign marked completed — all leads finished', [
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

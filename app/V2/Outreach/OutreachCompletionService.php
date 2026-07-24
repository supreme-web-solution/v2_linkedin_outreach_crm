<?php

namespace App\V2\Outreach;

use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLead;
use App\Models\V2OutreachRun;

class OutreachCompletionService
{
    public function maybeFinish(V2OutreachCampaign $campaign, ?V2OutreachRun $run = null): bool
    {
        $pending = V2OutreachLead::query()
            ->where('outreach_campaign_id', $campaign->id)
            ->whereIn('status', ['pending', 'running'])
            ->count();

        if ($pending > 0) {
            return false;
        }

        $campaign->update(['status' => 'completed']);

        if ($run) {
            $run->forceFill([
                'status' => 'completed',
                'finished_at' => now(),
            ])->save();
        }

        return true;
    }
}

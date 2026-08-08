<?php

namespace App\V2\Campaign;

use App\Jobs\V2\ProcessCampaignLeadJob;
use App\Models\V2CampaignLeadProgress;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CampaignDueLeadDispatcher
{
    /**
     * Re-queue classic campaign leads whose scheduled wait has elapsed, and periodically
     * re-check leads stuck on Invite Accepted? (missed delayed jobs / missed webhooks).
     *
     * @return array{dispatched: int}
     */
    public function dispatchDue(int $limit = 100): array
    {
        $dispatched = 0;
        $limit = max(1, min($limit, 500));

        $due = V2CampaignLeadProgress::query()
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->where('run_status', '<', 4)
            ->whereHas('campaign', fn ($q) => $q->whereIn('status', ['active', 'running']))
            ->whereHas('campaignLead', fn ($q) => $q->whereIn('status', ['pending', 'running']))
            ->orderBy('next_run_at')
            ->limit($limit)
            ->get(['id', 'campaign_id', 'campaign_lead_id']);

        foreach ($due as $progress) {
            if ($this->dispatchOne($progress)) {
                $dispatched++;
            }
        }

        $remaining = $limit - $dispatched;
        if ($remaining > 0) {
            // Leads waiting on invite acceptance with no reliable delayed job left in the queue.
            $waiting = V2CampaignLeadProgress::query()
                ->whereNull('acceptance_status')
                ->where('run_status', '>=', 1)
                ->where('run_status', '<', 4)
                ->whereHas('campaign', fn ($q) => $q->whereIn('status', ['active', 'running']))
                ->whereHas('campaignLead', fn ($q) => $q->whereIn('status', ['pending', 'running']))
                ->orderBy('updated_at')
                ->limit($remaining)
                ->get(['id', 'campaign_id', 'campaign_lead_id']);

            foreach ($waiting as $progress) {
                $throttleKey = 'campaign:invite-poll:'.$progress->campaign_id.':'.$progress->campaign_lead_id;
                if (! Cache::add($throttleKey, 1, now()->addMinutes(45))) {
                    continue;
                }

                if ($this->dispatchOne($progress)) {
                    $dispatched++;
                }
            }
        }

        if ($dispatched > 0) {
            Log::info('[Campaign] Dispatched due waiting leads', ['dispatched' => $dispatched]);
        }

        return ['dispatched' => $dispatched];
    }

    private function dispatchOne(V2CampaignLeadProgress $progress): bool
    {
        $lockKey = 'campaign:due-dispatch:'.$progress->campaign_id.':'.$progress->campaign_lead_id;
        if (! Cache::add($lockKey, 1, now()->addMinutes(10))) {
            return false;
        }

        ProcessCampaignLeadJob::dispatch(
            (int) $progress->campaign_id,
            (int) $progress->campaign_lead_id,
        );

        return true;
    }
}

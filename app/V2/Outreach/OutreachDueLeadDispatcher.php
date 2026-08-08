<?php

namespace App\V2\Outreach;

use App\Jobs\V2\ProcessOutreachLeadJob;
use App\Models\V2OutreachLeadProgress;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class OutreachDueLeadDispatcher
{
    /**
     * Re-queue outreach leads whose wait window elapsed, and periodically re-check
     * leads stuck on Has replied? / Invite accepted? across all channels.
     *
     * @return array{dispatched: int}
     */
    public function dispatchDue(int $limit = 100): array
    {
        $dispatched = 0;
        $limit = max(1, min($limit, 500));

        $due = V2OutreachLeadProgress::query()
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->where('run_status', '<', 4)
            ->whereHas('campaign', fn ($q) => $q->whereIn('status', ['active', 'running']))
            ->whereHas('lead', fn ($q) => $q->whereIn('status', ['pending', 'running']))
            ->orderBy('next_run_at')
            ->limit($limit)
            ->get(['id', 'outreach_campaign_id', 'outreach_lead_id']);

        foreach ($due as $progress) {
            if ($this->dispatchOne($progress)) {
                $dispatched++;
            }
        }

        $remaining = $limit - $dispatched;
        if ($remaining > 0) {
            $waiting = V2OutreachLeadProgress::query()
                ->where('run_status', '>=', 1)
                ->where('run_status', '<', 4)
                ->whereNotNull('next_run_at')
                ->whereHas('campaign', fn ($q) => $q->whereIn('status', ['active', 'running']))
                // Do not wake pause-on-reply (status=replied) leads — those are intentionally stopped.
                ->whereHas('lead', fn ($q) => $q->whereIn('status', ['pending', 'running']))
                ->orderBy('updated_at')
                ->limit($remaining)
                ->get(['id', 'outreach_campaign_id', 'outreach_lead_id']);

            foreach ($waiting as $progress) {
                $throttleKey = 'outreach:condition-poll:'.$progress->outreach_campaign_id.':'.$progress->outreach_lead_id;
                if (! Cache::add($throttleKey, 1, now()->addMinutes(45))) {
                    continue;
                }

                if ($this->dispatchOne($progress)) {
                    $dispatched++;
                }
            }
        }

        if ($dispatched > 0) {
            Log::info('[Outreach] Dispatched due waiting leads', ['dispatched' => $dispatched]);
        }

        return ['dispatched' => $dispatched];
    }

    private function dispatchOne(V2OutreachLeadProgress $progress): bool
    {
        $lockKey = 'outreach:due-dispatch:'.$progress->outreach_campaign_id.':'.$progress->outreach_lead_id;
        if (! Cache::add($lockKey, 1, now()->addMinutes(10))) {
            return false;
        }

        ProcessOutreachLeadJob::dispatch(
            (int) $progress->outreach_campaign_id,
            (int) $progress->outreach_lead_id,
        );

        return true;
    }
}

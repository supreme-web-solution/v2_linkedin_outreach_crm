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
     * @return array{dispatched: int, skipped_throttled: int, force: bool}
     */
    public function dispatchDue(int $limit = 100, bool $force = false): array
    {
        $dispatched = 0;
        $skippedThrottled = 0;
        $limit = max(1, min($limit, 500));
        $staggerSeconds = max(15, (int) config('services.unipile_pacing.campaign_lead_stagger_seconds', 45));

        $due = V2CampaignLeadProgress::query()
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->where('run_status', '<', 4)
            ->whereHas('campaign', fn ($q) => $q->whereIn('status', ['active', 'running']))
            ->whereHas('campaignLead', fn ($q) => $q->whereIn('status', ['pending', 'running']))
            ->orderBy('next_run_at')
            ->limit($limit)
            ->get(['id', 'campaign_id', 'campaign_lead_id', 'next_run_at']);

        foreach ($due as $progress) {
            if ($this->dispatchOne($progress, $dispatched * $staggerSeconds, $force)) {
                $dispatched++;
            }
        }

        $remaining = $limit - $dispatched;
        if ($remaining > 0) {
            // Invite-accepted waits: include future next_run_at so --force can re-poll now.
            $waiting = V2CampaignLeadProgress::query()
                ->whereNull('acceptance_status')
                ->where('run_status', '>=', 1)
                ->where('run_status', '<', 4)
                ->whereHas('campaign', fn ($q) => $q->whereIn('status', ['active', 'running']))
                ->whereHas('campaignLead', fn ($q) => $q->whereIn('status', ['pending', 'running']))
                ->orderBy('updated_at')
                ->limit($remaining)
                ->get(['id', 'campaign_id', 'campaign_lead_id', 'next_run_at']);

            foreach ($waiting as $progress) {
                $throttleKey = 'campaign:invite-poll:'.$progress->campaign_id.':'.$progress->campaign_lead_id;
                if (! $force && ! Cache::add($throttleKey, 1, now()->addMinutes(45))) {
                    $skippedThrottled++;

                    continue;
                }

                if ($force) {
                    Cache::put($throttleKey, 1, now()->addMinutes(45));
                }

                if ($this->dispatchOne($progress, $dispatched * $staggerSeconds, $force)) {
                    $dispatched++;
                }
            }
        }

        if ($dispatched > 0) {
            Log::info('[Campaign] Dispatched due waiting leads', [
                'dispatched' => $dispatched,
                'stagger_seconds' => $staggerSeconds,
                'force' => $force,
            ]);
        }

        return [
            'dispatched' => $dispatched,
            'skipped_throttled' => $skippedThrottled,
            'force' => $force,
        ];
    }

    private function dispatchOne(V2CampaignLeadProgress $progress, int $delaySeconds = 0, bool $force = false): bool
    {
        $lockKey = 'campaign:due-dispatch:'.$progress->campaign_id.':'.$progress->campaign_lead_id;
        if (! $force && ! Cache::add($lockKey, 1, now()->addMinutes(10))) {
            return false;
        }

        if ($force) {
            Cache::put($lockKey, 1, now()->addMinutes(10));
            // ProcessCampaignLeadJob bails when next_run_at is still in the future.
            if ($progress->next_run_at !== null && $progress->next_run_at->isFuture()) {
                $progress->forceFill(['next_run_at' => null])->save();
            }
        }

        $pending = ProcessCampaignLeadJob::dispatch(
            (int) $progress->campaign_id,
            (int) $progress->campaign_lead_id,
        );

        if ($delaySeconds > 0) {
            $pending->delay(now()->addSeconds($delaySeconds));
        }

        return true;
    }
}

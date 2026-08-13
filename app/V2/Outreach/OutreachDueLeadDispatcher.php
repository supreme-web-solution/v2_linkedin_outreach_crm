<?php

namespace App\V2\Outreach;

use App\Jobs\V2\ProcessOutreachLeadJob;
use App\Models\V2OutreachLeadProgress;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class OutreachDueLeadDispatcher
{
    /**
     * Re-queue outreach leads whose wait window elapsed, recover orphaned progress rows,
     * and periodically re-check leads stuck on Has replied? / Invite accepted?.
     *
     * @return array{dispatched: int, skipped_throttled: int, force: bool}
     */
    public function dispatchDue(int $limit = 100, bool $force = false): array
    {
        $dispatched = 0;
        $skippedThrottled = 0;
        $limit = max(1, min($limit, 500));
        $staggerSeconds = max(15, (int) config('services.unipile_pacing.outreach_lead_stagger_seconds', 60));

        $due = V2OutreachLeadProgress::query()
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->where('run_status', '<', 4)
            ->where('next_node_key', '!=', ProcessOutreachLeadJob::SEQUENCE_COMPLETE_KEY)
            ->whereHas('campaign', fn ($q) => $q->whereIn('status', ['active', 'running']))
            ->whereHas('lead', fn ($q) => $q->whereIn('status', ['pending', 'running']))
            ->orderBy('next_run_at')
            ->limit($limit)
            ->get(['id', 'outreach_campaign_id', 'outreach_lead_id', 'next_run_at']);

        foreach ($due as $progress) {
            if ($this->dispatchOne($progress, $dispatched * $staggerSeconds, $force)) {
                $dispatched++;
            }
        }

        $remaining = $limit - $dispatched;
        if ($remaining > 0) {
            $orphaned = V2OutreachLeadProgress::query()
                ->whereNull('next_run_at')
                ->where('run_status', '<', 4)
                ->where('next_node_key', '!=', ProcessOutreachLeadJob::SEQUENCE_COMPLETE_KEY)
                ->where('next_node_key', '>', 0)
                ->whereHas('campaign', fn ($q) => $q->whereIn('status', ['active', 'running']))
                ->whereHas('lead', fn ($q) => $q->whereIn('status', ['pending', 'running']))
                ->orderBy('updated_at')
                ->limit($remaining)
                ->get(['id', 'outreach_campaign_id', 'outreach_lead_id', 'next_run_at']);

            foreach ($orphaned as $progress) {
                if ($this->dispatchOne($progress, $dispatched * $staggerSeconds, $force)) {
                    $dispatched++;
                }
            }

            $remaining = $limit - $dispatched;
        }

        if ($remaining > 0) {
            // Condition waits (invite accepted / has replied): poll even when next_run_at is still future if --force.
            $waiting = V2OutreachLeadProgress::query()
                ->whereNull('acceptance_status')
                ->where('run_status', '>=', 1)
                ->where('run_status', '<', 4)
                ->where('next_node_key', '!=', ProcessOutreachLeadJob::SEQUENCE_COMPLETE_KEY)
                ->whereHas('campaign', fn ($q) => $q->whereIn('status', ['active', 'running']))
                ->whereHas('lead', fn ($q) => $q->whereIn('status', ['pending', 'running']))
                ->orderBy('updated_at')
                ->limit($remaining)
                ->get(['id', 'outreach_campaign_id', 'outreach_lead_id', 'next_run_at']);

            foreach ($waiting as $progress) {
                $throttleKey = 'outreach:condition-poll:'.$progress->outreach_campaign_id.':'.$progress->outreach_lead_id;
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
            Log::info('[Outreach] Dispatched due waiting leads', [
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

    private function dispatchOne(V2OutreachLeadProgress $progress, int $delaySeconds = 0, bool $force = false): bool
    {
        $lockKey = 'outreach:due-dispatch:'.$progress->outreach_campaign_id.':'.$progress->outreach_lead_id;
        if (! $force && ! Cache::add($lockKey, 1, now()->addMinutes(10))) {
            return false;
        }

        if ($force) {
            Cache::put($lockKey, 1, now()->addMinutes(10));
            if ($progress->next_run_at !== null && $progress->next_run_at->isFuture()) {
                $progress->forceFill(['next_run_at' => null])->save();
            }
        }

        $pending = ProcessOutreachLeadJob::dispatch(
            (int) $progress->outreach_campaign_id,
            (int) $progress->outreach_lead_id,
        );

        if ($delaySeconds > 0) {
            $pending->delay(now()->addSeconds($delaySeconds));
        }

        return true;
    }
}

<?php

namespace App\V2\Outreach;

use Illuminate\Support\Facades\Cache;

/**
 * Limits how many ProcessOutreachLeadJob handlers can run at once per user.
 * Extra leads stay queued and retry shortly — protects LinkedIn + shared workers.
 */
class OutreachConcurrencyLimiter
{
    public function maxInFlight(): int
    {
        return max(1, (int) config('services.unipile_pacing.outreach_inflight_per_user', 2));
    }

    public function current(int $userId): int
    {
        return max(0, (int) Cache::get($this->counterKey($userId), 0));
    }

    public function acquire(int $userId): bool
    {
        $max = $this->maxInFlight();
        $counterKey = $this->counterKey($userId);
        $gateKey = $this->gateKey($userId);

        return (bool) Cache::lock($gateKey, 5)->block(3, function () use ($counterKey, $max) {
            $current = max(0, (int) Cache::get($counterKey, 0));
            if ($current >= $max) {
                return false;
            }

            Cache::put($counterKey, $current + 1, now()->addHours(2));

            return true;
        });
    }

    public function release(int $userId): void
    {
        $counterKey = $this->counterKey($userId);
        $gateKey = $this->gateKey($userId);

        Cache::lock($gateKey, 5)->block(3, function () use ($counterKey) {
            $current = max(0, (int) Cache::get($counterKey, 0) - 1);
            if ($current <= 0) {
                Cache::forget($counterKey);
            } else {
                Cache::put($counterKey, $current, now()->addHours(2));
            }
        });
    }

    /**
     * @return array{limit: int, in_flight: int, available: int}
     */
    public function snapshot(int $userId): array
    {
        $limit = $this->maxInFlight();
        $inFlight = $this->current($userId);

        return [
            'limit' => $limit,
            'in_flight' => $inFlight,
            'available' => max(0, $limit - $inFlight),
        ];
    }

    private function counterKey(int $userId): string
    {
        return 'outreach:inflight:'.$userId;
    }

    private function gateKey(int $userId): string
    {
        return 'outreach:inflight-gate:'.$userId;
    }
}

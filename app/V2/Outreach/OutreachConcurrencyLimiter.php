<?php

namespace App\V2\Outreach;

use App\V2\Support\UserInFlightLimiter;

/**
 * Limits how many ProcessOutreachLeadJob handlers can run at once per user.
 * Extra leads stay queued and retry shortly — protects connected messaging accounts.
 */
class OutreachConcurrencyLimiter
{
    private UserInFlightLimiter $limiter;

    public function __construct()
    {
        $this->limiter = new UserInFlightLimiter(
            'outreach',
            max(1, (int) config('services.unipile_pacing.outreach_inflight_per_user', 2)),
            max(60, (int) config('services.unipile_pacing.inflight_lease_seconds', 1800)),
        );
    }

    public function maxInFlight(): int
    {
        return $this->limiter->maxInFlight();
    }

    public function current(int $userId): int
    {
        return $this->limiter->current($userId);
    }

    /**
     * @return string|null Lease id, or null when at capacity
     */
    public function acquire(int $userId): ?string
    {
        return $this->limiter->acquire($userId);
    }

    public function release(int $userId, ?string $leaseId = null): void
    {
        $this->limiter->release($userId, $leaseId);
    }

    /**
     * @return array{users: int, leases_freed: int}
     */
    public function recoverAll(): array
    {
        return $this->limiter->recoverAll();
    }

    /**
     * @return array{limit: int, in_flight: int, available: int}
     */
    public function snapshot(int $userId): array
    {
        return $this->limiter->snapshot($userId);
    }
}

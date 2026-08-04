<?php

namespace App\V2\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Per-user in-flight slot limiter with lease expiry.
 *
 * If a worker dies after acquire() and never release()s, the lease TTL
 * frees the slot automatically (and recoverAll() prunes eagerly).
 */
class UserInFlightLimiter
{
    public function __construct(
        private readonly string $namespace,
        private readonly int $maxInFlight,
        private readonly int $leaseSeconds = 1800,
    ) {
    }

    public function maxInFlight(): int
    {
        return max(1, $this->maxInFlight);
    }

    public function current(int $userId): int
    {
        return count($this->pruneAndLoad($userId));
    }

    /**
     * @return string|null Lease id when a slot was taken; null when at capacity
     */
    public function acquire(int $userId): ?string
    {
        $max = $this->maxInFlight();
        $leaseSeconds = max(60, $this->leaseSeconds);

        return Cache::lock($this->gateKey($userId), 5)->block(3, function () use ($userId, $max, $leaseSeconds) {
            $leases = $this->pruneAndLoad($userId);
            if (count($leases) >= $max) {
                $this->store($userId, $leases);

                return null;
            }

            $leaseId = (string) Str::uuid();
            $leases[$leaseId] = now()->addSeconds($leaseSeconds)->getTimestamp();
            $this->store($userId, $leases);
            $this->trackUser($userId);

            return $leaseId;
        });
    }

    public function release(int $userId, ?string $leaseId): void
    {
        if ($leaseId === null || $leaseId === '') {
            return;
        }

        Cache::lock($this->gateKey($userId), 5)->block(3, function () use ($userId, $leaseId) {
            $leases = $this->pruneAndLoad($userId);
            unset($leases[$leaseId]);
            $this->store($userId, $leases);
            if ($leases === []) {
                $this->untrackUser($userId);
            }
        });
    }

    /**
     * Drop expired leases for one user. Returns how many were removed.
     */
    public function recover(int $userId): int
    {
        $removed = 0;

        Cache::lock($this->gateKey($userId), 5)->block(3, function () use ($userId, &$removed) {
            $before = $this->loadRaw($userId);
            $after = $this->pruneLeases($before);
            $removed = max(0, count($before) - count($after));
            $this->store($userId, $after);
            if ($after === []) {
                $this->untrackUser($userId);
            }
        });

        return $removed;
    }

    /**
     * Prune expired leases for every tracked user.
     *
     * @return array{users: int, leases_freed: int}
     */
    public function recoverAll(): array
    {
        $users = array_map('intval', array_keys(Cache::get($this->usersKey(), [])));
        $freed = 0;
        foreach ($users as $userId) {
            if ($userId <= 0) {
                continue;
            }
            $freed += $this->recover($userId);
        }

        return [
            'users' => count($users),
            'leases_freed' => $freed,
        ];
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

    /**
     * @return array<string, int>
     */
    private function pruneAndLoad(int $userId): array
    {
        return $this->pruneLeases($this->loadRaw($userId));
    }

    /**
     * @return array<string, int>
     */
    private function loadRaw(int $userId): array
    {
        $raw = Cache::get($this->counterKey($userId));

        // Legacy integer counter from pre-lease limiter — reset so a dead worker
        // cannot leave the user permanently capped.
        if (is_int($raw) || (is_string($raw) && ctype_digit($raw))) {
            Cache::forget($this->counterKey($userId));

            return [];
        }

        if (! is_array($raw)) {
            return [];
        }

        $leases = [];
        foreach ($raw as $id => $expiresAt) {
            if (! is_string($id) || $id === '') {
                continue;
            }
            $leases[$id] = (int) $expiresAt;
        }

        return $leases;
    }

    /**
     * @param  array<string, int>  $leases
     * @return array<string, int>
     */
    private function pruneLeases(array $leases): array
    {
        $now = now()->getTimestamp();
        $kept = [];
        foreach ($leases as $id => $expiresAt) {
            if ($expiresAt > $now) {
                $kept[$id] = $expiresAt;
            }
        }

        return $kept;
    }

    /**
     * @param  array<string, int>  $leases
     */
    private function store(int $userId, array $leases): void
    {
        if ($leases === []) {
            Cache::forget($this->counterKey($userId));

            return;
        }

        $ttl = max(60, $this->leaseSeconds) + 3600;
        Cache::put($this->counterKey($userId), $leases, now()->addSeconds($ttl));
    }

    private function trackUser(int $userId): void
    {
        $users = Cache::get($this->usersKey(), []);
        if (! is_array($users)) {
            $users = [];
        }
        $users[(string) $userId] = true;
        Cache::put($this->usersKey(), $users, now()->addDay());
    }

    private function untrackUser(int $userId): void
    {
        $users = Cache::get($this->usersKey(), []);
        if (! is_array($users)) {
            return;
        }
        unset($users[(string) $userId]);
        if ($users === []) {
            Cache::forget($this->usersKey());
        } else {
            Cache::put($this->usersKey(), $users, now()->addDay());
        }
    }

    private function counterKey(int $userId): string
    {
        return $this->namespace.':inflight:'.$userId;
    }

    private function gateKey(int $userId): string
    {
        return $this->namespace.':inflight-gate:'.$userId;
    }

    private function usersKey(): string
    {
        return $this->namespace.':inflight-users';
    }
}

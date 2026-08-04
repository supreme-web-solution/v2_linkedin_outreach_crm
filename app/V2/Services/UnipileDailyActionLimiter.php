<?php

namespace App\V2\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Per-user daily caps for Unipile actions (invites, new chats, messages).
 *
 * Counters are atomic cache increments, so concurrent queue workers cannot
 * race past a cap. A cap of 0 or less means unlimited. When a cap is hit,
 * callers should defer the action to resumeAt() instead of failing it.
 */
class UnipileDailyActionLimiter
{
    public const ACTION_INVITES = 'invites';

    public const ACTION_NEW_CHATS = 'new_chats';

    public const ACTION_MESSAGES = 'messages';

    public function limitFor(string $action): int
    {
        return (int) match ($action) {
            self::ACTION_INVITES => config('services.unipile_pacing.daily_invites', 40),
            self::ACTION_NEW_CHATS => config('services.unipile_pacing.daily_new_chats', 60),
            self::ACTION_MESSAGES => config('services.unipile_pacing.daily_messages', 200),
            default => 0,
        };
    }

    public function label(string $action): string
    {
        return match ($action) {
            self::ACTION_INVITES => 'connection invites',
            self::ACTION_NEW_CHATS => 'new chats',
            self::ACTION_MESSAGES => 'messages',
            default => 'actions',
        };
    }

    /**
     * Atomically reserve quota. Returns false (and leaves the counter
     * untouched) when the daily cap would be exceeded.
     */
    public function tryConsume(int $userId, string $action, int $count = 1): bool
    {
        $limit = $this->limitFor($action);
        if ($limit <= 0) {
            return true;
        }

        $key = $this->key($userId, $action);
        Cache::add($key, 0, now()->endOfDay()->addHours(2));
        $new = (int) Cache::increment($key, $count);

        if ($new > $limit) {
            Cache::decrement($key, $count);
            app(OpsAlertService::class)->dailyLimitHit($userId, $action, $limit);

            return false;
        }

        return true;
    }

    public function used(int $userId, string $action): int
    {
        return max(0, (int) Cache::get($this->key($userId, $action), 0));
    }

    /**
     * Give back quota after a failed send (e.g. temporary provider limit).
     */
    public function release(int $userId, string $action, int $count = 1): void
    {
        $limit = $this->limitFor($action);
        if ($limit <= 0 || $count <= 0) {
            return;
        }

        $key = $this->key($userId, $action);
        $used = (int) Cache::get($key, 0);
        if ($used <= 0) {
            return;
        }

        Cache::decrement($key, min($count, $used));
    }

    public function remaining(int $userId, string $action): int
    {
        $limit = $this->limitFor($action);
        if ($limit <= 0) {
            return PHP_INT_MAX;
        }

        return max(0, $limit - $this->used($userId, $action));
    }

    public function hasQuota(int $userId, string $action, int $count = 1): bool
    {
        return $this->remaining($userId, $action) >= $count;
    }

    /**
     * When deferred work should resume: shortly after midnight with random
     * jitter, so a fleet of deferred jobs doesn't burst at 00:00 sharp.
     */
    public function resumeAt(): CarbonInterface
    {
        return now()->addDay()->startOfDay()->addMinutes(random_int(5, 50));
    }

    /**
     * @return array{limit: int, used: int, remaining: int}
     */
    public function snapshot(int $userId, string $action): array
    {
        $limit = $this->limitFor($action);

        return [
            'limit' => $limit,
            'used' => $this->used($userId, $action),
            'remaining' => $limit <= 0 ? -1 : $this->remaining($userId, $action),
        ];
    }

    private function key(int $userId, string $action): string
    {
        return 'unipile_quota:'.$userId.':'.$action.':'.now()->toDateString();
    }
}

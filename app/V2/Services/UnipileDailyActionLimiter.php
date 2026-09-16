<?php

namespace App\V2\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Per-user pacing caps for Unipile actions (invites, new chats, messages, IG DMs).
 *
 * Counters are atomic cache increments, so concurrent queue workers cannot
 * race past a cap. A cap of 0 or less means unlimited.
 *
 * Invite caps (invites / noted_invites) pause until the next rolling window
 * (~daily_window_hours, typically tomorrow). Other Soci pacing caps pause
 * for daily_resume_after_hours (default ~1h). LinkedIn/Instagram hard
 * provider limits are still handled by UnipileTemporaryLimitGuard.
 */
class UnipileDailyActionLimiter
{
    public const ACTION_INVITES = 'invites';

    /** Connection invites that include a personal note (LinkedIn ~5/day). */
    public const ACTION_NOTED_INVITES = 'noted_invites';

    public const ACTION_NEW_CHATS = 'new_chats';

    public const ACTION_MESSAGES = 'messages';

    /** Instagram DMs — much tighter than LinkedIn. */
    public const ACTION_INSTAGRAM_DMS = 'instagram_dms';

    /** Account-wide LinkedIn cool-down label (no separate daily cap). */
    public const ACTION_LINKEDIN = 'linkedin';

    /**
     * Blank-note invites use the regular invite cap; noted invites use the tighter noted cap.
     */
    public static function inviteActionForMessage(?string $message): string
    {
        return trim((string) $message) !== ''
            ? self::ACTION_NOTED_INVITES
            : self::ACTION_INVITES;
    }

    public static function isInviteAction(string $action): bool
    {
        return in_array($action, [self::ACTION_INVITES, self::ACTION_NOTED_INVITES], true);
    }

    public function limitFor(string $action): int
    {
        return (int) match ($action) {
            self::ACTION_INVITES => config('services.unipile_pacing.daily_invites', 40),
            self::ACTION_NOTED_INVITES => config('services.unipile_pacing.daily_noted_invites', 5),
            self::ACTION_NEW_CHATS => config('services.unipile_pacing.daily_new_chats', 60),
            self::ACTION_MESSAGES => config('services.unipile_pacing.daily_messages', 200),
            self::ACTION_INSTAGRAM_DMS => config('services.unipile_pacing.instagram_daily_dms', 15),
            default => 0,
        };
    }

    public function label(string $action): string
    {
        return match ($action) {
            self::ACTION_INVITES => 'connection invites',
            self::ACTION_NOTED_INVITES => 'noted connection invites',
            self::ACTION_NEW_CHATS => 'new chats',
            self::ACTION_MESSAGES => 'messages',
            self::ACTION_INSTAGRAM_DMS => 'Instagram DMs',
            self::ACTION_LINKEDIN => 'LinkedIn actions',
            default => 'actions',
        };
    }

    /**
     * Atomically reserve quota. Returns false (and leaves the counter
     * untouched) when the pacing cap would be exceeded.
     */
    public function tryConsume(int $userId, string $action, int $count = 1, ?int $limitOverride = null): bool
    {
        $limit = $limitOverride ?? $this->limitFor($action);
        if ($limit <= 0) {
            return true;
        }

        $this->releaseHoldIfExpired($userId, $action);

        if ($this->isOnHold($userId, $action)) {
            return false;
        }

        $key = $this->key($userId, $action);
        Cache::add($key, 0, now()->addHours($this->windowHours())->addHour());
        $new = (int) Cache::increment($key, $count);

        if ($new > $limit) {
            Cache::decrement($key, $count);
            $resume = $this->resumeAt($action);
            $this->putHold($userId, $action, $resume);
            app(OpsAlertService::class)->dailyLimitHit($userId, $action, $limit);

            return false;
        }

        return true;
    }

    public function used(int $userId, string $action): int
    {
        $this->releaseHoldIfExpired($userId, $action);

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

    public function remaining(int $userId, string $action, ?int $limitOverride = null): int
    {
        $limit = $limitOverride ?? $this->limitFor($action);
        if ($limit <= 0) {
            return PHP_INT_MAX;
        }

        if ($this->isOnHold($userId, $action)) {
            return 0;
        }

        return max(0, $limit - $this->used($userId, $action));
    }

    public function hasQuota(int $userId, string $action, int $count = 1, ?int $limitOverride = null): bool
    {
        return $this->remaining($userId, $action, $limitOverride) >= $count;
    }

    /**
     * When deferred work should resume after a Soci pacing cap.
     * Invites wait for the next rolling window; other actions use ~1 hour.
     */
    public function resumeAt(?string $action = null): CarbonInterface
    {
        if ($action !== null && self::isInviteAction($action)) {
            return $this->nextWindowResumeAt();
        }

        $hours = max(1, (int) config('services.unipile_pacing.daily_resume_after_hours', 1));
        $jitterMin = max(0, (int) config('services.unipile_pacing.daily_resume_jitter_min_minutes', 5));
        $jitterMax = max($jitterMin, (int) config('services.unipile_pacing.daily_resume_jitter_max_minutes', 20));

        return now()->addHours($hours)->addMinutes(random_int($jitterMin, $jitterMax));
    }

    /**
     * Existing hold end, or a freshly computed resume time for this action.
     */
    public function resumeAtFor(int $userId, string $action): CarbonInterface
    {
        return $this->holdUntil($userId, $action) ?? $this->resumeAt($action);
    }

    public function isOnHold(int $userId, string $action): bool
    {
        $until = Cache::get($this->holdKey($userId, $action));
        if ($until === null) {
            return false;
        }

        return now()->getTimestamp() < (int) $until;
    }

    public function holdUntil(int $userId, string $action): ?CarbonInterface
    {
        $this->releaseHoldIfExpired($userId, $action);

        $until = Cache::get($this->holdKey($userId, $action));
        if ($until === null) {
            return null;
        }

        $ts = (int) $until;
        if (now()->getTimestamp() >= $ts) {
            return null;
        }

        return Carbon::createFromTimestamp($ts);
    }

    /**
     * @return array{limit: int, used: int, remaining: int}
     */
    public function snapshot(int $userId, string $action, ?int $limitOverride = null): array
    {
        $limit = $limitOverride ?? $this->limitFor($action);

        return [
            'limit' => $limit,
            'used' => $this->used($userId, $action),
            'remaining' => $limit <= 0 ? -1 : $this->remaining($userId, $action, $limit),
        ];
    }

    private function nextWindowResumeAt(): CarbonInterface
    {
        $windowSeconds = max(3600, $this->windowHours() * 3600);
        $nextStart = ((int) floor(now()->timestamp / $windowSeconds) + 1) * $windowSeconds;
        $jitterMin = max(0, (int) config('services.unipile_pacing.daily_resume_jitter_min_minutes', 5));
        $jitterMax = max($jitterMin, (int) config('services.unipile_pacing.daily_resume_jitter_max_minutes', 20));

        $resume = Carbon::createFromTimestamp($nextStart)
            ->addMinutes(random_int($jitterMin, $jitterMax));

        // Never schedule in the past if the clock is near a bucket boundary.
        if ($resume->lessThanOrEqualTo(now())) {
            return now()->addMinutes(max(1, $jitterMin ?: 5));
        }

        return $resume;
    }

    private function windowHours(): int
    {
        return max(1, (int) config('services.unipile_pacing.daily_window_hours', 24));
    }

    private function key(int $userId, string $action): string
    {
        // Rolling window id — not calendar midnight — so resume timing matches the hold.
        $bucket = (int) floor(now()->timestamp / max(3600, $this->windowHours() * 3600));

        return 'unipile_quota:'.$userId.':'.$action.':w'.$bucket;
    }

    private function holdKey(int $userId, string $action): string
    {
        return 'unipile_quota_hold:'.$userId.':'.$action;
    }

    private function putHold(int $userId, string $action, CarbonInterface $resumeAt): void
    {
        Cache::put(
            $this->holdKey($userId, $action),
            $resumeAt->getTimestamp(),
            $resumeAt->copy()->addHour(),
        );
    }

    private function releaseHoldIfExpired(int $userId, string $action): void
    {
        $until = Cache::get($this->holdKey($userId, $action));
        if ($until === null) {
            return;
        }

        if (now()->getTimestamp() < (int) $until) {
            return;
        }

        Cache::forget($this->holdKey($userId, $action));
        Cache::forget($this->key($userId, $action));
    }
}

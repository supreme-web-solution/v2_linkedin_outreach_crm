<?php

namespace App\V2\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * LinkedIn / Unipile burst limits (HTTP 422 cannot_resend_yet, etc.).
 * Distinct from daily caps — these clear after a short cool-down.
 */
class UnipileTemporaryLimitGuard
{
    public function isTemporaryLimit(Throwable|string|null $error): bool
    {
        if ($error === null) {
            return false;
        }

        $haystack = strtolower(is_string($error) ? $error : $error->getMessage());
        if ($haystack === '') {
            return false;
        }

        return str_contains($haystack, 'cannot_resend_yet')
            || str_contains($haystack, 'temporary provider limit')
            || str_contains($haystack, 'try again later')
            || str_contains($haystack, 'too many requests')
            || str_contains($haystack, 'rate limit')
            || str_contains($haystack, 'rate_limit')
            || str_contains($haystack, 'slow down');
    }

    public function isLimited(int $userId, string $action = 'invites'): bool
    {
        $resume = $this->resumeAt($userId, $action);

        return $resume !== null && $resume->isFuture();
    }

    public function resumeAt(int $userId, string $action = 'invites'): ?CarbonInterface
    {
        $ts = Cache::get($this->key($userId, $action));
        if (! is_numeric($ts)) {
            return null;
        }

        $at = now()->setTimestamp((int) $ts);

        return $at->isFuture() ? $at : null;
    }

    /**
     * Mark this user/action as cooling down. Returns when work may resume.
     */
    public function markLimited(int $userId, string $action = 'invites', ?CarbonInterface $until = null): CarbonInterface
    {
        $until ??= now()->addMinutes(random_int(
            max(15, (int) config('services.unipile_pacing.temp_limit_min_minutes', 45)),
            max(30, (int) config('services.unipile_pacing.temp_limit_max_minutes', 90)),
        ));

        Cache::put($this->key($userId, $action), $until->getTimestamp(), $until->copy()->addHour());

        return $until;
    }

    /**
     * @return array{status: string, next_run_at: CarbonInterface, payload: array<string, mixed>}
     */
    public function deferredResult(int $userId, string $action = 'invites', ?string $error = null): array
    {
        $resumeAt = $this->resumeAt($userId, $action) ?? $this->markLimited($userId, $action);

        return [
            'status' => 'deferred',
            'next_run_at' => $resumeAt,
            'error_message' => $error,
            'payload' => [
                'reason' => 'temporary_'.$action.'_limit',
                'resume_at' => $resumeAt->toIso8601String(),
            ],
        ];
    }

    private function key(int $userId, string $action): string
    {
        return 'unipile_temp_limit:'.$userId.':'.$action;
    }
}

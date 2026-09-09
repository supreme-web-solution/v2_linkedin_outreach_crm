<?php

namespace App\V2\Services;

use App\V2\Integrations\Unipile\UnipileException;
use App\V2\Outreach\OutreachChannelRegistry;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Unipile provider burst limits (HTTP 422/429, cannot_resend_yet, not_sending_now, etc.).
 * Distinct from daily caps — short cool-downs first; repeated failures escalate
 * to next-day pause so leads stop looking "stuck" in the UI.
 *
 * Cool-downs are per platform (linkedin, whatsapp, instagram, …) so one channel
 * pausing does not block another user's queue or a different platform.
 */
class UnipileTemporaryLimitGuard
{
    public const ACTION_LINKEDIN = UnipileDailyActionLimiter::ACTION_LINKEDIN;

    /** @var list<string> */
    public const PACED_CHANNELS = [
        'linkedin',
        'email',
        'whatsapp',
        'instagram',
        'telegram',
        'twitter',
    ];

    /** @var list<string> */
    private const LEGACY_LINKEDIN_ACTIONS = [
        UnipileDailyActionLimiter::ACTION_INVITES,
        UnipileDailyActionLimiter::ACTION_NEW_CHATS,
        UnipileDailyActionLimiter::ACTION_MESSAGES,
    ];

    public static function supportsChannel(string $channel): bool
    {
        return in_array(strtolower(trim($channel)), self::PACED_CHANNELS, true);
    }

    public function isTemporaryLimit(Throwable|string|null $error): bool
    {
        if ($error === null) {
            return false;
        }

        if ($error instanceof UnipileException) {
            if (in_array($error->statusCode, [429, 502, 503, 504], true)) {
                return true;
            }
            $code = strtolower((string) ($error->context['error_code'] ?? ''));
            if ($code !== '' && $this->matchesTemporaryHaystack($code)) {
                return true;
            }
        }

        $haystack = strtolower(is_string($error) ? $error : $error->getMessage());

        return $haystack !== '' && $this->matchesTemporaryHaystack($haystack);
    }

    /** Provider blip (502/nginx) — short retry, not a daily-limit escalation. */
    public function isProviderOutage(Throwable|string|null $error): bool
    {
        if ($error instanceof UnipileException) {
            return in_array($error->statusCode, [502, 503, 504], true);
        }

        $haystack = strtolower(is_string($error) ? $error : (string) ($error?->getMessage() ?? ''));

        return $haystack !== '' && (
            str_contains($haystack, 'http 502')
            || str_contains($haystack, 'http 503')
            || str_contains($haystack, 'http 504')
            || str_contains($haystack, 'bad gateway')
            || str_contains($haystack, 'gateway timeout')
            || str_contains($haystack, 'service unavailable')
            || str_contains($haystack, 'upstream')
        );
    }

    public function isLimited(int $userId, string $action = self::ACTION_LINKEDIN): bool
    {
        $action = $this->normalizeAction($action);

        if ($action === self::ACTION_LINKEDIN) {
            return $this->resolveActiveCoolDown($userId, self::ACTION_LINKEDIN)['resume_at'] !== null;
        }

        $resume = $this->resumeAt($userId, $action);

        return $resume !== null && $resume->isFuture();
    }

    public function isEscalated(int $userId, string $action = self::ACTION_LINKEDIN): bool
    {
        $action = $this->normalizeAction($action);

        if ($action === self::ACTION_LINKEDIN) {
            $resolved = $this->resolveActiveCoolDown($userId, self::ACTION_LINKEDIN);
            if ($resolved['resume_at'] === null) {
                return false;
            }

            return (bool) Cache::get($this->escalatedKey($userId, $resolved['action']), false);
        }

        return (bool) Cache::get($this->escalatedKey($userId, $action), false)
            && $this->isLimited($userId, $action);
    }

    public function resumeAt(int $userId, string $action = self::ACTION_LINKEDIN): ?CarbonInterface
    {
        // Read the exact cache key so legacy invite/message cool-downs still resolve.
        $action = strtolower(trim($action));
        $ts = Cache::get($this->key($userId, $action));
        if (! is_numeric($ts)) {
            return null;
        }

        $at = now()->setTimestamp((int) $ts);

        return $at->isFuture() ? $at : null;
    }

    public function failureHits(int $userId, string $action = self::ACTION_LINKEDIN): int
    {
        return max(0, (int) Cache::get($this->hitsKey($userId, $this->normalizeAction($action)), 0));
    }

    public function clearFailureStreak(int $userId, string $action = self::ACTION_LINKEDIN): void
    {
        $action = $this->normalizeAction($action);
        Cache::forget($this->hitsKey($userId, $action));
        Cache::forget($this->escalatedKey($userId, $action));
        Cache::forget($this->outageHitsKey($userId, $action));
    }

    public function markLimited(int $userId, string $action = self::ACTION_LINKEDIN, ?CarbonInterface $until = null): CarbonInterface
    {
        $action = $this->normalizeAction($action);
        $until ??= now()->addMinutes(random_int(
            max(15, (int) config('services.unipile_pacing.temp_limit_min_minutes', 45)),
            max(30, (int) config('services.unipile_pacing.temp_limit_max_minutes', 90)),
        ));

        Cache::put($this->key($userId, $action), $until->getTimestamp(), $until->copy()->addHour());

        return $until;
    }

    /**
     * @return array{
     *     status: string,
     *     next_run_at: ?CarbonInterface,
     *     error_message: ?string,
     *     payload: array<string, mixed>
     * }
     */
    public function deferredResult(int $userId, string $action = self::ACTION_LINKEDIN, ?string $error = null): array
    {
        $action = $this->normalizeAction($action);
        $fromApiFailure = $error !== null && $this->isTemporaryLimit($error);
        $providerOutage = $error !== null && $this->isProviderOutage($error);

        if ($fromApiFailure) {
            // Gateway/provider blips: short retry → longer backoff → stop hammering.
            if ($providerOutage) {
                return $this->deferredProviderOutage($userId, $action);
            }

            $hits = $this->incrementFailureHits($userId, $action);
            $escalateAfter = max(1, (int) config('services.unipile_pacing.temp_limit_escalate_after', 2));
            $escalated = $hits >= $escalateAfter;

            $resumeAt = $escalated
                ? $this->markLimited($userId, $action, app(UnipileDailyActionLimiter::class)->resumeAt())
                : $this->markLimited($userId, $action);

            $this->rememberEscalated($userId, $action, $escalated, $resumeAt);

            return [
                'status' => 'deferred',
                'next_run_at' => $resumeAt,
                'error_message' => null,
                'payload' => [
                    'reason' => $escalated
                        ? 'escalated_'.$action.'_limit'
                        : 'temporary_'.$action.'_limit',
                    'escalated' => $escalated,
                    'hits' => $hits,
                    'channel' => $action,
                    'resume_at' => $resumeAt->toIso8601String(),
                ],
            ];
        }

        $resolved = $this->resolveActiveCoolDown($userId, $action);
        $resumeAt = $resolved['resume_at'] ?? $this->markLimited($userId, $action);
        $activeAction = $resolved['resume_at'] ? $resolved['action'] : $action;
        $escalated = $this->isEscalated($userId, $activeAction);

        return [
            'status' => 'deferred',
            'next_run_at' => $resumeAt,
            'error_message' => null,
            'payload' => [
                'reason' => $escalated
                    ? 'escalated_'.$activeAction.'_limit'
                    : 'temporary_'.$activeAction.'_limit',
                'escalated' => $escalated,
                'hits' => $this->failureHits($userId, $activeAction),
                'channel' => $activeAction,
                'resume_at' => $resumeAt->toIso8601String(),
            ],
        ];
    }

    /**
     * @return array{
     *     status: string,
     *     next_run_at: ?CarbonInterface,
     *     error_message: ?string,
     *     payload: array<string, mixed>
     * }
     */
    private function deferredProviderOutage(int $userId, string $action): array
    {
        $hits = $this->incrementOutageHits($userId, $action);
        $shortRetries = max(1, (int) config('services.unipile_pacing.provider_outage_short_retries', 6));
        $giveUpAfter = max($shortRetries + 1, (int) config('services.unipile_pacing.provider_outage_give_up_after', 12));
        $label = $this->platformLabel($action);

        if ($hits >= $giveUpAfter) {
            Cache::forget($this->key($userId, $action));

            return [
                'status' => 'failed_temporary',
                'next_run_at' => null,
                'error_message' => "{$label} provider (Unipile) stayed unavailable after {$hits} retries. Resume the campaign when the provider is healthy — this is not a copy or list problem.",
                'payload' => [
                    'reason' => 'temporary_provider_outage_exhausted',
                    'escalated' => true,
                    'hits' => $hits,
                    'channel' => $action,
                ],
            ];
        }

        if ($hits > $shortRetries) {
            $resumeAt = now()->addMinutes(random_int(
                max(15, (int) config('services.unipile_pacing.provider_outage_long_min_minutes', 30)),
                max(20, (int) config('services.unipile_pacing.provider_outage_long_max_minutes', 60)),
            ));
        } else {
            $resumeAt = now()->addMinutes(random_int(2, 6));
        }

        Cache::put($this->key($userId, $action), $resumeAt->getTimestamp(), $resumeAt->copy()->addHour());

        return [
            'status' => 'deferred',
            'next_run_at' => $resumeAt,
            'error_message' => null,
            'payload' => [
                'reason' => 'temporary_provider_outage',
                'escalated' => $hits > $shortRetries,
                'hits' => $hits,
                'channel' => $action,
                'resume_at' => $resumeAt->toIso8601String(),
            ],
        ];
    }

    /**
     * @return array{
     *     active: bool,
     *     escalated: bool,
     *     resume_at: ?string,
     *     hits: int,
     *     action: string,
     *     channel: string,
     *     label: string,
     *     message: ?string
     * }
     */
    public function snapshot(int $userId, string $action = self::ACTION_LINKEDIN): array
    {
        $action = $this->normalizeAction($action);
        $resolved = $this->resolveActiveCoolDown($userId, $action);
        $resumeAt = $resolved['resume_at'];
        $activeAction = $resolved['action'];
        $active = $resumeAt !== null;
        $escalated = $active && $this->isEscalated($userId, $activeAction);
        $label = $this->platformLabel($activeAction);

        $message = null;
        if ($active && $escalated) {
            $message = $label.' is still limiting sends on this account. Steps pause until '
                .$resumeAt->format('g:i A').' to protect your account — this is '.$label.'’s rule.';
        } elseif ($active) {
            $outageHits = $this->outageHits($userId, $activeAction);
            if ($outageHits > 0) {
                $message = $label.' provider is temporarily unavailable (Unipile). We retry automatically'
                    .($resumeAt ? ' around '.$resumeAt->format('g:i A') : '')
                    .' — not a problem with your message or list.';
            } else {
                $message = $label.' hit a temporary send limit. Remaining steps wait until '
                    .$resumeAt->format('g:i A').' and then continue automatically — expected pacing, not a bug.';
            }
        }

        return [
            'active' => $active,
            'escalated' => $escalated,
            'resume_at' => $resumeAt?->toIso8601String(),
            'hits' => $this->failureHits($userId, $activeAction),
            'action' => $activeAction,
            'channel' => $activeAction,
            'label' => $label,
            'message' => $message,
        ];
    }

    /**
     * @param  list<string>  $channels
     * @return list<array{
     *     active: bool,
     *     escalated: bool,
     *     resume_at: ?string,
     *     hits: int,
     *     action: string,
     *     channel: string,
     *     label: string,
     *     message: ?string
     * }>
     */
    public function snapshotsForChannels(int $userId, array $channels): array
    {
        $out = [];
        foreach (array_values(array_unique($channels)) as $channel) {
            $channel = strtolower(trim((string) $channel));
            if (! self::supportsChannel($channel)) {
                continue;
            }
            $out[] = $this->snapshot($userId, $channel);
        }

        return $out;
    }

    public function platformLabel(string $action): string
    {
        $action = $this->normalizeAction($action);

        if (self::supportsChannel($action)) {
            return OutreachChannelRegistry::channelLabel($action);
        }

        return app(UnipileDailyActionLimiter::class)->label($action);
    }

    private function matchesTemporaryHaystack(string $haystack): bool
    {
        return str_contains($haystack, 'cannot_resend_yet')
            || str_contains($haystack, 'not_sending_now')
            || str_contains($haystack, 'not_sending')
            || str_contains($haystack, 'cannot_send')
            || str_contains($haystack, 'temporary provider limit')
            || str_contains($haystack, 'try again later')
            || str_contains($haystack, 'too many requests')
            || str_contains($haystack, 'too_many_requests')
            || str_contains($haystack, 'rate limit')
            || str_contains($haystack, 'rate_limit')
            || str_contains($haystack, 'slow down')
            || str_contains($haystack, 'http 429')
            || str_contains($haystack, 'http 502')
            || str_contains($haystack, 'http 503')
            || str_contains($haystack, 'http 504')
            || str_contains($haystack, 'bad gateway')
            || str_contains($haystack, 'gateway timeout')
            || str_contains($haystack, 'service unavailable')
            || str_contains($haystack, 'busy with another action');
    }

    /**
     * @return array{action: string, resume_at: ?CarbonInterface}
     */
    private function resolveActiveCoolDown(int $userId, string $preferred): array
    {
        $preferred = $this->normalizeAction($preferred);

        $candidates = $preferred === self::ACTION_LINKEDIN
            ? array_values(array_unique(array_merge([$preferred], self::LEGACY_LINKEDIN_ACTIONS)))
            : [$preferred];

        $bestAction = $preferred;
        $bestResume = null;

        foreach ($candidates as $candidate) {
            $resume = $this->resumeAt($userId, $candidate);
            if ($resume === null) {
                continue;
            }
            if ($bestResume === null || $resume->greaterThan($bestResume)) {
                $bestResume = $resume;
                $bestAction = $this->normalizeAction($candidate);
            }
        }

        return ['action' => $bestAction, 'resume_at' => $bestResume];
    }

    private function normalizeAction(string $action): string
    {
        $action = strtolower(trim($action));

        if (in_array($action, self::LEGACY_LINKEDIN_ACTIONS, true)) {
            return self::ACTION_LINKEDIN;
        }

        return $action !== '' ? $action : self::ACTION_LINKEDIN;
    }

    private function incrementFailureHits(int $userId, string $action): int
    {
        $key = $this->hitsKey($userId, $action);
        Cache::add($key, 0, now()->endOfDay()->addHours(2));

        return (int) Cache::increment($key);
    }

    private function incrementOutageHits(int $userId, string $action): int
    {
        $key = $this->outageHitsKey($userId, $action);
        Cache::add($key, 0, now()->addHours(6));

        return (int) Cache::increment($key);
    }

    public function outageHits(int $userId, string $action = self::ACTION_LINKEDIN): int
    {
        return max(0, (int) Cache::get($this->outageHitsKey($userId, $this->normalizeAction($action)), 0));
    }

    private function rememberEscalated(int $userId, string $action, bool $escalated, CarbonInterface $until): void
    {
        if ($escalated) {
            Cache::put($this->escalatedKey($userId, $action), true, $until->copy()->addHour());

            return;
        }

        Cache::forget($this->escalatedKey($userId, $action));
    }

    private function key(int $userId, string $action): string
    {
        return 'unipile_temp_limit:'.$userId.':'.$action;
    }

    private function hitsKey(int $userId, string $action): string
    {
        return 'unipile_temp_limit_hits:'.$userId.':'.$action.':'.now()->toDateString();
    }

    private function outageHitsKey(int $userId, string $action): string
    {
        return 'unipile_provider_outage_hits:'.$userId.':'.$action;
    }

    private function escalatedKey(int $userId, string $action): string
    {
        return 'unipile_temp_limit_escalated:'.$userId.':'.$action;
    }
}

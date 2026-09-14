<?php

namespace App\V2\Services;

use App\Models\V2IntegrationAccount;
use App\V2\Outreach\OutreachChannelRegistry;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Instagram-safe send pacing. Meta flags accounts that burst DMs or keep
 * retrying handle lookups. LinkedIn keeps its existing daily limiter.
 */
class ChannelPacingService
{
    public function __construct(
        private readonly UnipileDailyActionLimiter $limiter,
    ) {}

    public function isWarmingUp(int $userId, string $channel = 'instagram'): bool
    {
        $until = $this->metaTimestamp($userId, $channel, 'warmup_until');

        return $until !== null && $until->isFuture();
    }

    public function quietUntil(int $userId, string $channel = 'instagram'): ?CarbonInterface
    {
        $until = $this->metaTimestamp($userId, $channel, 'quiet_until');

        return $until !== null && $until->isFuture() ? $until : null;
    }

    public function instagramDailyLimit(int $userId): int
    {
        $key = $this->isWarmingUp($userId)
            ? 'instagram_daily_dms_warmup'
            : 'instagram_daily_dms';

        return max(1, (int) config('services.unipile_pacing.'.$key, $this->isWarmingUp($userId) ? 5 : 15));
    }

    public function instagramHourlyLimit(int $userId): int
    {
        $key = $this->isWarmingUp($userId)
            ? 'instagram_hourly_dms_warmup'
            : 'instagram_hourly_dms';

        return max(1, (int) config('services.unipile_pacing.'.$key, $this->isWarmingUp($userId) ? 2 : 3));
    }

    public function quotaAction(string $channel, string $action, ?string $message = null): ?string
    {
        return match ($channel) {
            'linkedin' => match ($action) {
                'send_invite' => UnipileDailyActionLimiter::inviteActionForMessage($message),
                'send_message' => UnipileDailyActionLimiter::ACTION_MESSAGES,
                default => null,
            },
            'instagram' => $action === 'send_message'
                ? UnipileDailyActionLimiter::ACTION_INSTAGRAM_DMS
                : null,
            default => null,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    public function deferInstagramSend(int $userId): ?array
    {
        $quietUntil = $this->quietUntil($userId);
        if ($quietUntil !== null) {
            return [
                'status' => 'deferred',
                'next_run_at' => $quietUntil->copy()->addMinutes(random_int(5, 25)),
                'payload' => [
                    'reason' => 'instagram_quiet_period',
                    'channel' => 'instagram',
                    'limit' => 0,
                ],
            ];
        }

        $hourlyLimit = $this->instagramHourlyLimit($userId);
        if (! $this->tryConsumeHourly($userId, $hourlyLimit)) {
            return [
                'status' => 'deferred',
                'next_run_at' => now()->addHour()->startOfHour()->addMinutes(random_int(8, 25)),
                'payload' => [
                    'reason' => 'hourly_instagram_dms_limit',
                    'channel' => 'instagram',
                    'limit' => $hourlyLimit,
                ],
            ];
        }

        $dailyLimit = $this->instagramDailyLimit($userId);
        if ($this->limiter->tryConsume($userId, UnipileDailyActionLimiter::ACTION_INSTAGRAM_DMS, 1, $dailyLimit)) {
            return null;
        }

        $this->releaseHourly($userId);

        return [
            'status' => 'deferred',
            'next_run_at' => $this->limiter->resumeAt(),
            'payload' => [
                'reason' => 'daily_instagram_dms_limit',
                'channel' => 'instagram',
                'limit' => $dailyLimit,
            ],
        ];
    }

    public function leadStaggerSeconds(int $userId, string $channel): int
    {
        if ($channel !== 'instagram') {
            return max(5, (int) config('services.unipile_pacing.outreach_lead_stagger_seconds', 60));
        }

        $key = $this->isWarmingUp($userId)
            ? 'instagram_lead_stagger_warmup_seconds'
            : 'instagram_lead_stagger_seconds';

        return max(120, (int) config('services.unipile_pacing.'.$key, $this->isWarmingUp($userId) ? 480 : 300));
    }

    public function leadStaggerJitterSeconds(string $channel): int
    {
        if ($channel !== 'instagram') {
            return 0;
        }

        return max(0, (int) config('services.unipile_pacing.instagram_lead_stagger_jitter_seconds', 90));
    }

    public function dispatchDelaySeconds(int $userId, string $channel, int $index): int
    {
        $stagger = $this->leadStaggerSeconds($userId, $channel);
        $jitter = $this->leadStaggerJitterSeconds($channel);

        return ($index * $stagger) + ($jitter > 0 ? random_int(0, $jitter) : 0);
    }

    public function handleResolveRetryAt(string $channel): CarbonInterface
    {
        if ($channel === 'instagram') {
            return now()->addMinutes(random_int(
                max(15, (int) config('services.unipile_pacing.instagram_handle_retry_min_minutes', 20)),
                max(20, (int) config('services.unipile_pacing.instagram_handle_retry_max_minutes', 35)),
            ));
        }

        return now()->addMinutes(2);
    }

    public function shouldBulkResolveHandles(string $channel): bool
    {
        return $channel !== 'instagram';
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     */
    public function primaryChannelFromNodes(array $nodes): string
    {
        return (string) (OutreachChannelRegistry::firstActionChannelForNodes($nodes) ?? 'linkedin');
    }

    private function tryConsumeHourly(int $userId, int $limit): bool
    {
        if ($limit <= 0) {
            return true;
        }

        $key = $this->hourlyKey($userId);
        Cache::add($key, 0, now()->addHours(2));
        $new = (int) Cache::increment($key);

        if ($new > $limit) {
            Cache::decrement($key);

            return false;
        }

        return true;
    }

    private function releaseHourly(int $userId): void
    {
        $key = $this->hourlyKey($userId);
        $used = (int) Cache::get($key, 0);
        if ($used > 0) {
            Cache::decrement($key);
        }
    }

    private function hourlyKey(int $userId): string
    {
        return 'unipile_quota_hour:'.$userId.':instagram_dms:'.now()->format('Y-m-d-H');
    }

    private function metaTimestamp(int $userId, string $channel, string $field): ?Carbon
    {
        $provider = OutreachChannelRegistry::channels()[$channel]['integration_provider'] ?? $channel;
        $account = V2IntegrationAccount::query()
            ->where('user_id', $userId)
            ->where('provider', $provider)
            ->latest('id')
            ->first();

        $raw = is_array($account?->meta) ? ($account->meta[$field] ?? null) : null;
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }
}

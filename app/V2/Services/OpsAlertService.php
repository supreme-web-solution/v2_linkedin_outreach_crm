<?php

namespace App\V2\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpsAlertService
{
    public function notify(string $type, string $message, array $context = []): void
    {
        Log::warning('[ops.alert] '.$message, array_merge(['type' => $type], $context));

        $this->postSlack($type, $message, $context);
    }

    public function dailyLimitHit(int $userId, string $action, int $limit): void
    {
        if (! config('services.ops.alert_daily_limit_hits', true)) {
            return;
        }

        $cacheKey = 'ops_alert:daily_limit:'.$userId.':'.$action.':'.now()->toDateString();
        if (! Cache::add($cacheKey, true, now()->endOfDay())) {
            return;
        }

        $label = app(UnipileDailyActionLimiter::class)->label($action);
        $this->notify('daily_limit', "Daily {$label} limit reached ({$limit}/day) for user #{$userId}", [
            'user_id' => $userId,
            'action' => $action,
            'limit' => $limit,
        ]);
    }

    public function queueHealth(string $message, array $context = []): void
    {
        if (! config('services.ops.alert_queue_health', true)) {
            return;
        }

        $cacheKey = 'ops_alert:queue:'.md5($message.':'.now()->toDateString());
        if (! Cache::add($cacheKey, true, now()->addHours(6))) {
            return;
        }

        $this->notify('queue_health', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function postSlack(string $type, string $message, array $context): void
    {
        $webhook = trim((string) config('services.ops.slack_webhook_url', ''));
        if ($webhook === '') {
            return;
        }

        $lines = ["*[{$type}]* {$message}"];
        foreach ($context as $key => $value) {
            if (is_scalar($value)) {
                $lines[] = "• {$key}: {$value}";
            }
        }

        try {
            Http::timeout(5)->post($webhook, [
                'text' => implode("\n", $lines),
            ]);
        } catch (\Throwable $e) {
            Log::debug('Ops Slack webhook failed', ['error' => $e->getMessage()]);
        }
    }
}

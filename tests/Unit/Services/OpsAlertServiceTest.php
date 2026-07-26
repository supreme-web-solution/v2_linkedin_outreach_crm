<?php

namespace Tests\Unit\Services;

use App\V2\Services\OpsAlertService;
use App\V2\Services\UnipileDailyActionLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class OpsAlertServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_limit_alert_is_throttled_to_once_per_day(): void
    {
        Log::shouldReceive('warning')->once();

        config()->set('services.ops.alert_daily_limit_hits', true);
        config()->set('services.ops.slack_webhook_url', '');

        $service = app(OpsAlertService::class);
        $service->dailyLimitHit(42, UnipileDailyActionLimiter::ACTION_INVITES, 40);
        $service->dailyLimitHit(42, UnipileDailyActionLimiter::ACTION_INVITES, 40);

        $this->assertTrue(Cache::has('ops_alert:daily_limit:42:invites:'.now()->toDateString()));
    }

    public function test_slack_webhook_is_called_when_configured(): void
    {
        Http::fake();
        config()->set('services.ops.slack_webhook_url', 'https://hooks.slack.com/test');

        app(OpsAlertService::class)->notify('test', 'Hello ops', ['foo' => 'bar']);

        Http::assertSent(fn ($request) => $request->url() === 'https://hooks.slack.com/test'
            && str_contains($request->body(), 'Hello ops'));
    }
}

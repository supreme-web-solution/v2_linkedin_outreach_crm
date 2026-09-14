<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Models\V2IntegrationAccount;
use App\V2\Services\ChannelPacingService;
use App\V2\Services\UnipileDailyActionLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ChannelPacingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_quiet_period_defers_instagram_sends(): void
    {
        $user = User::factory()->create();
        V2IntegrationAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'instagram',
            'provider_account_id' => 'ig_1',
            'status' => 'active',
            'meta' => [
                'quiet_until' => now()->addHours(6)->toIso8601String(),
                'warmup_until' => now()->addDays(7)->toIso8601String(),
            ],
        ]);

        $deferred = app(ChannelPacingService::class)->deferInstagramSend($user->id);

        $this->assertSame('deferred', $deferred['status'] ?? null);
        $this->assertSame('instagram_quiet_period', $deferred['payload']['reason'] ?? null);
        $this->assertTrue($deferred['next_run_at']->isFuture());
    }

    public function test_warmup_uses_tighter_daily_cap(): void
    {
        Config::set('services.unipile_pacing.instagram_daily_dms', 15);
        Config::set('services.unipile_pacing.instagram_daily_dms_warmup', 5);

        $user = User::factory()->create();
        V2IntegrationAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'instagram',
            'provider_account_id' => 'ig_1',
            'status' => 'active',
            'meta' => [
                'warmup_until' => now()->addDays(3)->toIso8601String(),
            ],
        ]);

        $pacing = app(ChannelPacingService::class);

        $this->assertTrue($pacing->isWarmingUp($user->id));
        $this->assertSame(5, $pacing->instagramDailyLimit($user->id));
        $this->assertGreaterThanOrEqual(120, $pacing->leadStaggerSeconds($user->id, 'instagram'));
    }

    public function test_instagram_hourly_cap_defers_after_limit(): void
    {
        Config::set('services.unipile_pacing.instagram_hourly_dms', 1);
        Config::set('services.unipile_pacing.instagram_hourly_dms_warmup', 1);
        Config::set('services.unipile_pacing.instagram_daily_dms', 15);

        $user = User::factory()->create();
        $pacing = app(ChannelPacingService::class);

        $this->assertNull($pacing->deferInstagramSend($user->id));
        $deferred = $pacing->deferInstagramSend($user->id);

        $this->assertSame('hourly_instagram_dms_limit', $deferred['payload']['reason'] ?? null);
        $this->assertSame(1, app(UnipileDailyActionLimiter::class)->used($user->id, UnipileDailyActionLimiter::ACTION_INSTAGRAM_DMS));
    }

    public function test_instagram_does_not_bulk_resolve_handles(): void
    {
        $pacing = app(ChannelPacingService::class);

        $this->assertFalse($pacing->shouldBulkResolveHandles('instagram'));
        $this->assertTrue($pacing->shouldBulkResolveHandles('telegram'));
        $this->assertTrue($pacing->handleResolveRetryAt('instagram')->greaterThan(now()->addMinutes(10)));
    }

    public function test_linkedin_stagger_stays_on_existing_pace(): void
    {
        Config::set('services.unipile_pacing.outreach_lead_stagger_seconds', 60);

        $this->assertSame(60, app(ChannelPacingService::class)->leadStaggerSeconds(1, 'linkedin'));
    }
}

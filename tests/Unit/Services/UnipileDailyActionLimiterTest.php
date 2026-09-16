<?php

namespace Tests\Unit\Services;

use App\V2\Services\UnipileDailyActionLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class UnipileDailyActionLimiterTest extends TestCase
{
    private UnipileDailyActionLimiter $limiter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->limiter = new UnipileDailyActionLimiter();
    }

    public function test_consumes_until_cap_then_blocks(): void
    {
        Config::set('services.unipile_pacing.daily_new_chats', 2);

        $this->assertTrue($this->limiter->tryConsume(1, UnipileDailyActionLimiter::ACTION_NEW_CHATS));
        $this->assertTrue($this->limiter->tryConsume(1, UnipileDailyActionLimiter::ACTION_NEW_CHATS));
        $this->assertFalse($this->limiter->tryConsume(1, UnipileDailyActionLimiter::ACTION_NEW_CHATS));

        $this->assertSame(2, $this->limiter->used(1, UnipileDailyActionLimiter::ACTION_NEW_CHATS));
        $this->assertSame(0, $this->limiter->remaining(1, UnipileDailyActionLimiter::ACTION_NEW_CHATS));
    }

    public function test_failed_consume_does_not_change_counter(): void
    {
        Config::set('services.unipile_pacing.daily_invites', 1);

        $this->assertTrue($this->limiter->tryConsume(5, UnipileDailyActionLimiter::ACTION_INVITES));
        $this->assertFalse($this->limiter->tryConsume(5, UnipileDailyActionLimiter::ACTION_INVITES));
        $this->assertFalse($this->limiter->tryConsume(5, UnipileDailyActionLimiter::ACTION_INVITES));

        $this->assertSame(1, $this->limiter->used(5, UnipileDailyActionLimiter::ACTION_INVITES));
    }

    public function test_counters_are_per_user(): void
    {
        Config::set('services.unipile_pacing.daily_invites', 1);

        $this->assertTrue($this->limiter->tryConsume(10, UnipileDailyActionLimiter::ACTION_INVITES));
        $this->assertTrue($this->limiter->tryConsume(11, UnipileDailyActionLimiter::ACTION_INVITES));
        $this->assertFalse($this->limiter->tryConsume(10, UnipileDailyActionLimiter::ACTION_INVITES));
    }

    public function test_zero_limit_means_unlimited(): void
    {
        Config::set('services.unipile_pacing.daily_messages', 0);

        for ($i = 0; $i < 25; $i++) {
            $this->assertTrue($this->limiter->tryConsume(2, UnipileDailyActionLimiter::ACTION_MESSAGES));
        }

        $this->assertTrue($this->limiter->hasQuota(2, UnipileDailyActionLimiter::ACTION_MESSAGES));
    }

    public function test_resume_at_is_about_one_hour_not_midnight_for_non_invite(): void
    {
        Config::set('services.unipile_pacing.daily_resume_after_hours', 1);
        Config::set('services.unipile_pacing.daily_resume_jitter_min_minutes', 5);
        Config::set('services.unipile_pacing.daily_resume_jitter_max_minutes', 20);

        $resumeAt = $this->limiter->resumeAt(UnipileDailyActionLimiter::ACTION_MESSAGES);

        $this->assertTrue($resumeAt->between(
            now()->addHour()->addMinutes(4),
            now()->addHour()->addMinutes(25),
        ));
        $this->assertFalse($resumeAt->isTomorrow());
    }

    public function test_invite_resume_at_is_next_window_not_one_hour(): void
    {
        Config::set('services.unipile_pacing.daily_window_hours', 24);
        Config::set('services.unipile_pacing.daily_resume_jitter_min_minutes', 5);
        Config::set('services.unipile_pacing.daily_resume_jitter_max_minutes', 20);

        $windowSeconds = 24 * 3600;
        $nextStart = ((int) floor(now()->timestamp / $windowSeconds) + 1) * $windowSeconds;

        $resumeAt = $this->limiter->resumeAt(UnipileDailyActionLimiter::ACTION_INVITES);

        $this->assertTrue($resumeAt->between(
            \Carbon\Carbon::createFromTimestamp($nextStart)->addMinutes(4),
            \Carbon\Carbon::createFromTimestamp($nextStart)->addMinutes(25),
        ));
        // Invite holds park until the next rolling window — not the ~1h non-invite resume.
        $this->assertFalse($resumeAt->between(
            now()->addHour()->addMinutes(4),
            now()->addHour()->addMinutes(25),
        ));
    }

    public function test_noted_invite_resume_at_is_next_window(): void
    {
        Config::set('services.unipile_pacing.daily_window_hours', 24);
        Config::set('services.unipile_pacing.daily_resume_jitter_min_minutes', 0);
        Config::set('services.unipile_pacing.daily_resume_jitter_max_minutes', 0);

        $windowSeconds = 24 * 3600;
        $nextStart = ((int) floor(now()->timestamp / $windowSeconds) + 1) * $windowSeconds;

        $resumeAt = $this->limiter->resumeAt(UnipileDailyActionLimiter::ACTION_NOTED_INVITES);

        $this->assertSame($nextStart, $resumeAt->getTimestamp());
    }

    public function test_invite_hold_reuses_same_resume_time(): void
    {
        Config::set('services.unipile_pacing.daily_invites', 1);
        Config::set('services.unipile_pacing.daily_window_hours', 24);
        Config::set('services.unipile_pacing.daily_resume_jitter_min_minutes', 5);
        Config::set('services.unipile_pacing.daily_resume_jitter_max_minutes', 20);

        $this->assertTrue($this->limiter->tryConsume(42, UnipileDailyActionLimiter::ACTION_INVITES));
        $this->assertFalse($this->limiter->tryConsume(42, UnipileDailyActionLimiter::ACTION_INVITES));

        $firstHold = $this->limiter->holdUntil(42, UnipileDailyActionLimiter::ACTION_INVITES);
        $this->assertNotNull($firstHold);
        $this->assertTrue($this->limiter->isOnHold(42, UnipileDailyActionLimiter::ACTION_INVITES));

        $this->assertFalse($this->limiter->tryConsume(42, UnipileDailyActionLimiter::ACTION_INVITES));
        $secondHold = $this->limiter->holdUntil(42, UnipileDailyActionLimiter::ACTION_INVITES);

        $this->assertNotNull($secondHold);
        $this->assertSame($firstHold->getTimestamp(), $secondHold->getTimestamp());
        $this->assertSame(
            $firstHold->getTimestamp(),
            $this->limiter->resumeAtFor(42, UnipileDailyActionLimiter::ACTION_INVITES)->getTimestamp(),
        );
        $this->assertFalse($firstHold->between(
            now()->addHour()->addMinutes(4),
            now()->addHour()->addMinutes(25),
        ));
    }

    public function test_invite_cap_uses_configured_limit_not_hardcoded(): void
    {
        Config::set('services.unipile_pacing.daily_invites', 3);

        $this->assertTrue($this->limiter->tryConsume(77, UnipileDailyActionLimiter::ACTION_INVITES));
        $this->assertTrue($this->limiter->tryConsume(77, UnipileDailyActionLimiter::ACTION_INVITES));
        $this->assertTrue($this->limiter->tryConsume(77, UnipileDailyActionLimiter::ACTION_INVITES));
        $this->assertFalse($this->limiter->tryConsume(77, UnipileDailyActionLimiter::ACTION_INVITES));
        $this->assertSame(3, $this->limiter->used(77, UnipileDailyActionLimiter::ACTION_INVITES));
    }

    public function test_hold_expires_and_opens_a_fresh_window(): void
    {
        Config::set('services.unipile_pacing.daily_invites', 1);
        Config::set('services.unipile_pacing.daily_window_hours', 24);

        $this->assertTrue($this->limiter->tryConsume(7, UnipileDailyActionLimiter::ACTION_INVITES));
        $this->assertFalse($this->limiter->tryConsume(7, UnipileDailyActionLimiter::ACTION_INVITES));

        // Simulate hold expiry (next window).
        Cache::put(
            'unipile_quota_hold:7:invites',
            now()->subMinute()->getTimestamp(),
            now()->addHour(),
        );

        $this->assertTrue($this->limiter->tryConsume(7, UnipileDailyActionLimiter::ACTION_INVITES));
    }

    public function test_instagram_limit_override_uses_warmup_cap(): void
    {
        Config::set('services.unipile_pacing.instagram_daily_dms', 15);

        $this->assertTrue($this->limiter->tryConsume(9, UnipileDailyActionLimiter::ACTION_INSTAGRAM_DMS, 1, 1));
        $this->assertFalse($this->limiter->tryConsume(9, UnipileDailyActionLimiter::ACTION_INSTAGRAM_DMS, 1, 1));
        $this->assertSame(1, $this->limiter->used(9, UnipileDailyActionLimiter::ACTION_INSTAGRAM_DMS));
    }
}

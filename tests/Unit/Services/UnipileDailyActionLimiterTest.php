<?php

namespace Tests\Unit\Services;

use App\V2\Services\UnipileDailyActionLimiter;
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

    public function test_resume_at_is_tomorrow(): void
    {
        $resumeAt = $this->limiter->resumeAt();

        $this->assertTrue($resumeAt->isTomorrow());
    }
}

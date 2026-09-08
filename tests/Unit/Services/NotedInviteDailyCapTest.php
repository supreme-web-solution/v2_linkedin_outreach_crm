<?php

namespace Tests\Unit\Services;

use App\V2\Services\UnipileDailyActionLimiter;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class NotedInviteDailyCapTest extends TestCase
{
    public function test_blank_note_uses_regular_invite_bucket(): void
    {
        $this->assertSame(
            UnipileDailyActionLimiter::ACTION_INVITES,
            UnipileDailyActionLimiter::inviteActionForMessage(''),
        );
        $this->assertSame(
            UnipileDailyActionLimiter::ACTION_INVITES,
            UnipileDailyActionLimiter::inviteActionForMessage('   '),
        );
    }

    public function test_noted_invite_uses_tight_bucket(): void
    {
        $this->assertSame(
            UnipileDailyActionLimiter::ACTION_NOTED_INVITES,
            UnipileDailyActionLimiter::inviteActionForMessage('Hi {{firstName}}'),
        );
    }

    public function test_noted_invite_cap_defaults_to_five(): void
    {
        Config::set('services.unipile_pacing.daily_noted_invites', 5);
        $limiter = app(UnipileDailyActionLimiter::class);

        $this->assertSame(5, $limiter->limitFor(UnipileDailyActionLimiter::ACTION_NOTED_INVITES));

        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($limiter->tryConsume(99, UnipileDailyActionLimiter::ACTION_NOTED_INVITES));
        }
        $this->assertFalse($limiter->tryConsume(99, UnipileDailyActionLimiter::ACTION_NOTED_INVITES));
    }
}

<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\V2\Services\ChannelConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ChannelConnectionInstagramSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_instagram_connect_starts_quiet_warmup(): void
    {
        Config::set('services.unipile_pacing.instagram_warmup_days', 7);
        Config::set('services.unipile_pacing.instagram_quiet_hours_after_connect', 12);

        $user = User::factory()->create();
        $account = app(ChannelConnectionService::class)->persistFromUnipilePayload($user->id, 'instagram', [
            'account_id' => 'acc_ig_1',
            'type' => 'INSTAGRAM',
            'name' => 'test.ig',
        ]);

        $meta = is_array($account->meta) ? $account->meta : [];

        $this->assertSame('active', $account->status);
        $this->assertNotEmpty($meta['quiet_until'] ?? null);
        $this->assertNotEmpty($meta['warmup_until'] ?? null);
        $this->assertTrue(\Illuminate\Support\Carbon::parse($meta['quiet_until'])->isFuture());
        $this->assertTrue(\Illuminate\Support\Carbon::parse($meta['warmup_until'])->greaterThan(now()->addDays(5)));
    }
}

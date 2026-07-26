<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\V2\Services\DailyUsageQuotaService;
use App\V2\Services\EmailEnrichmentLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailEnrichmentLimiterTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_quota_and_pending_count_work(): void
    {
        $user = User::factory()->create([
            'daily_profile_email_scraping_count' => 3,
            'daily_profile_email_scraping_reset_at' => now()->toDateString(),
        ]);

        $items = app(DailyUsageQuotaService::class)->forUser($user)['items'];
        $email = collect($items)->firstWhere('key', 'email_enrichment');

        $this->assertSame(3, $email['used']);
        $this->assertSame(0, app(EmailEnrichmentLimiter::class)->pendingJobCount($user->id));
    }
}

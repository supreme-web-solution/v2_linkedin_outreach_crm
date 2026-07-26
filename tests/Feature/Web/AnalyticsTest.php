<?php

namespace Tests\Feature\Web;

use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Services\UnipileDailyActionLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('billing.require_entitlement', false);
    }

    public function test_analytics_page_includes_daily_quota_usage(): void
    {
        $user = $this->userWithOrg();

        app(UnipileDailyActionLimiter::class)->tryConsume($user->id, UnipileDailyActionLimiter::ACTION_INVITES);
        app(UnipileDailyActionLimiter::class)->tryConsume($user->id, UnipileDailyActionLimiter::ACTION_NEW_CHATS);
        app(UnipileDailyActionLimiter::class)->tryConsume($user->id, UnipileDailyActionLimiter::ACTION_NEW_CHATS);

        $user->forceFill([
            'daily_profile_email_scraping_count' => 12,
            'daily_profile_email_scraping_reset_at' => now()->toDateString(),
        ])->save();

        $response = $this->actingAs($user)->get(route('analytics'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('crm/Analytics')
            ->has('dailyQuotas.items', 4)
            ->where('dailyQuotas.items.0.key', 'invites')
            ->where('dailyQuotas.items.0.used', 1)
            ->where('dailyQuotas.items.1.key', 'new_chats')
            ->where('dailyQuotas.items.1.used', 2)
            ->where('dailyQuotas.items.3.key', 'email_enrichment')
            ->where('dailyQuotas.items.3.used', 12)
            ->has('dailyQuotas.resets_at')
        );
    }

    private function userWithOrg(): User
    {
        $user = User::factory()->create();
        $organization = V2Organization::query()->create([
            'name' => 'Analytics Org',
            'slug' => 'analytics-org-'.$user->id,
            'status' => 'active',
        ]);

        V2OrganizationUser::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'capabilities' => ['*'],
            'status' => 'active',
        ]);

        $user->forceFill(['current_organization_id' => $organization->id])->save();

        return $user->fresh();
    }
}

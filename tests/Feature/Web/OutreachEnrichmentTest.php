<?php

namespace Tests\Feature\Web;

use App\Models\Audience;
use App\Models\AudienceList;
use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OutreachEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('billing.require_entitlement', false);
        config()->set('services.email_scraping.daily_limit_per_user', 2);
    }

    public function test_outreach_fetch_emails_caps_to_daily_remaining(): void
    {
        Queue::fake();

        $user = $this->userWithOrg();
        $audienceId = 700002;
        Audience::query()->create([
            'audience_name' => 'Batch',
            'audience_id' => $audienceId,
            'user_id' => $user->id,
        ]);

        foreach (['a', 'b', 'c'] as $slug) {
            AudienceList::query()->create([
                'audience_id' => $audienceId,
                'con_public_identifier' => $slug,
            ]);
        }

        $response = $this->actingAs($user)->postJson(route('outreach.enrich.fetch-emails'), [
            'lead_lists' => [['list_hash' => (string) $audienceId, 'list_src' => 'aud']],
            'node_model' => [],
        ]);

        $response->assertOk();
        $response->assertJsonPath('queued', 2);
        $response->assertJsonPath('skipped', 1);
    }

    private function userWithOrg(): User
    {
        $user = User::factory()->create();
        $organization = V2Organization::query()->create([
            'name' => 'Outreach Org',
            'slug' => 'outreach-org-'.$user->id,
            'status' => 'active',
        ]);

        V2OrganizationUser::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'capabilities' => ['*'],
            'status' => 'active',
        ]);

        $user->forceFill([
            'current_organization_id' => $organization->id,
            'email_verified_at' => now(),
        ])->save();

        return $user->fresh();
    }
}

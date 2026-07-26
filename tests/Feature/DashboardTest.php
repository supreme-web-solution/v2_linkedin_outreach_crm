<?php

namespace Tests\Feature;

use App\Models\Audience;
use App\Models\AudienceList;
use App\Models\SnLead;
use App\Models\SnLeadList;
use App\Models\User;
use App\Models\V2Call;
use App\Models\V2Campaign;
use App\Models\V2Conversation;
use App\Models\V2Lead;
use App\Models\V2OutreachImportLead;
use App\Models\V2OutreachImportList;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('billing.require_entitlement', false);
    }

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_dashboard_lead_count_matches_leads_page_and_decrements_on_delete(): void
    {
        $user = User::factory()->create();

        $audience = Audience::query()->create([
            'audience_name' => 'Test audience',
            'audience_id' => 900001,
            'user_id' => $user->id,
        ]);

        $audienceLead = AudienceList::query()->create(['audience_id' => $audience->audience_id]);
        AudienceList::query()->create(['audience_id' => $audience->audience_id]);

        SnLeadList::query()->create([
            'name' => 'SN list',
            'list_hash' => 800001,
            'user_id' => $user->id,
        ]);
        SnLead::query()->create(['first_name' => 'A', 'sn_list_id' => 800001]);

        V2Lead::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_profile_id' => 'legacy-lead',
            'full_name' => 'Legacy lead row',
        ]);

        $dashboard = $this->actingAs($user)->get(route('dashboard'));
        $dashboard->assertOk();
        $dashboard->assertInertia(fn ($page) => $page->where('stats.leads', 3));

        $leadsPage = $this->actingAs($user)->get(route('leads'));
        $leadsPage->assertInertia(fn ($page) => $page->where('stats.total_leads', 3));

        $audienceLead->delete();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('stats.leads', 2));
    }

    public function test_dashboard_includes_imported_csv_contacts_in_lead_total(): void
    {
        $user = User::factory()->create();

        $importList = V2OutreachImportList::query()->create([
            'user_id' => $user->id,
            'list_hash' => 'import-hash-1',
            'name' => 'CSV Import',
            'lead_count' => 2,
        ]);

        foreach (['a@example.com', 'b@example.com'] as $email) {
            V2OutreachImportLead::query()->create([
                'import_list_id' => $importList->id,
                'email' => $email,
                'full_name' => 'Import Lead',
            ]);
        }

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('stats.leads', 2)
                ->where('stats.imported_leads', 2)
                ->where('stats.linkedin_leads', 0)
            );
    }

    public function test_dashboard_conversations_and_calls_match_active_pipeline_only(): void
    {
        $user = $this->userWithOrg();

        V2Call::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'prospect_name' => 'Active one',
            'status' => 'engaged',
        ]);
        V2Call::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'prospect_name' => 'Completed',
            'status' => 'completed',
        ]);

        V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'status' => 'active',
        ]);
        V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'status' => 'active',
        ]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('stats.conversations', 2)
                ->where('stats.calls', 1)
            );
    }

    public function test_dashboard_campaign_count_excludes_deleted_campaigns(): void
    {
        $user = $this->userWithOrg();

        $campaign = V2Campaign::query()->create([
            'organization_id' => $user->current_organization_id,
            'user_id' => $user->id,
            'name' => 'To delete',
            'status' => 'draft',
        ]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('stats.campaigns', 1));

        $campaign->delete();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('stats.campaigns', 0));
    }

    private function userWithOrg(): User
    {
        $user = User::factory()->create();
        $organization = V2Organization::query()->create([
            'name' => 'Dashboard Org',
            'slug' => 'dashboard-org-'.$user->id,
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

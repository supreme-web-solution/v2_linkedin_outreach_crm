<?php

namespace Tests\Unit\V2\Services;

use App\Models\User;
use App\Models\V2Call;
use App\Models\V2Conversation;
use App\Models\V2Message;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLead;
use App\V2\Services\AcquisitionFunnelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcquisitionFunnelServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_funnel_returns_all_seven_stages(): void
    {
        [$user, $org] = $this->userWithOrg();

        $funnel = app(AcquisitionFunnelService::class)->forUser($user);

        $this->assertCount(7, $funnel['stages']);
        $this->assertSame('targeted', $funnel['stages'][0]['key']);
        $this->assertSame('customers', $funnel['stages'][6]['key']);
    }

    public function test_funnel_counts_outreach_pipeline_stages(): void
    {
        [$user, $org] = $this->userWithOrg();

        $campaign = V2OutreachCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'Test',
            'template_type' => 'linkedin_only',
            'status' => 'active',
            'node_model' => [],
        ]);

        V2OutreachLead::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'full_name' => 'Pending',
            'status' => 'pending',
            'meta' => [],
        ]);

        V2OutreachLead::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'full_name' => 'Contacted',
            'status' => 'running',
            'meta' => [],
        ]);

        V2OutreachLead::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'full_name' => 'Replied',
            'status' => 'replied',
            'meta' => ['qualification' => ['stage' => 'qualified']],
        ]);

        V2OutreachLead::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'full_name' => 'Customer',
            'status' => 'replied',
            'meta' => ['qualification' => ['stage' => 'customer']],
        ]);

        $funnel = app(AcquisitionFunnelService::class)->forUser($user);

        $this->assertSame(4, $funnel['summary']['targeted']);
        $this->assertSame(3, $funnel['summary']['contacted']);
        $this->assertSame(2, $funnel['summary']['responses']);
        $this->assertSame(1, $funnel['summary']['qualified']);
        $this->assertSame(1, $funnel['summary']['customers']);
    }

    public function test_funnel_counts_demo_bookings(): void
    {
        [$user, $org] = $this->userWithOrg();

        V2Call::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'prospect_name' => 'Demo',
            'status' => 'booked',
            'scheduled_call_at' => now()->addDay(),
        ]);

        $funnel = app(AcquisitionFunnelService::class)->forUser($user);

        $this->assertSame(1, $funnel['summary']['demos']);
    }

    public function test_funnel_counts_meaningful_conversations(): void
    {
        [$user] = $this->userWithOrg();

        $conversation = V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'status' => 'active',
            'meta' => ['outreach_campaign_id' => 1, 'source' => 'outreach'],
        ]);

        V2Message::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'body' => 'Yes tell me more',
        ]);
        V2Message::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'body' => 'Great question',
        ]);

        $funnel = app(AcquisitionFunnelService::class)->forUser($user);

        $this->assertGreaterThanOrEqual(1, $funnel['summary']['conversations']);
    }

    /**
     * @return array{0:User, 1:V2Organization}
     */
    private function userWithOrg(): array
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Funnel Org',
            'slug' => 'funnel-org-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        $user->forceFill(['current_organization_id' => $org->id])->save();
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        return [$user->fresh(), $org];
    }
}

<?php

namespace Tests\Unit\V2\Outreach;

use App\Jobs\V2\ProcessOutreachLeadJob;
use App\Models\User;
use App\Models\V2IntegrationAccount;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLead;
use App\Models\V2OutreachLeadProgress;
use App\V2\Outreach\OutreachRunDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OutreachRunDispatcherNextRunAtTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_persists_staggered_next_run_at_on_progress(): void
    {
        Queue::fake([ProcessOutreachLeadJob::class]);
        config()->set('services.unipile_pacing.outreach_lead_stagger_seconds', 60);

        $user = $this->userWithOrg();
        $this->connectLinkedIn($user);

        $campaign = V2OutreachCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'name' => 'Stagger durable',
            'status' => 'preparing',
            'node_model' => [
                ['key' => 1, 'type' => 'action', 'channel' => 'linkedin', 'action' => 'send_invite', 'label' => 'Send Invite'],
            ],
        ]);

        $leadA = V2OutreachLead::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'full_name' => 'Lead A',
            'status' => 'pending',
        ]);
        $leadB = V2OutreachLead::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'full_name' => 'Lead B',
            'status' => 'pending',
        ]);

        $before = now();
        $result = app(OutreachRunDispatcher::class)->dispatch($campaign->fresh(), (int) $user->current_organization_id);

        $this->assertSame(2, $result['queued_leads']);
        $this->assertGreaterThan(0, $result['run_id']);

        $progressA = V2OutreachLeadProgress::query()->where('outreach_lead_id', $leadA->id)->first();
        $progressB = V2OutreachLeadProgress::query()->where('outreach_lead_id', $leadB->id)->first();

        $this->assertNotNull($progressA?->next_run_at);
        $this->assertNotNull($progressB?->next_run_at);
        $this->assertTrue($progressA->next_run_at->between($before->copy()->subSecond(), $before->copy()->addSeconds(2)));
        $this->assertTrue($progressB->next_run_at->between($before->copy()->addSeconds(58), $before->copy()->addSeconds(62)));

        Queue::assertPushed(ProcessOutreachLeadJob::class, 2);
    }

    public function test_dispatch_does_not_clobber_existing_future_next_run_at(): void
    {
        Queue::fake([ProcessOutreachLeadJob::class]);
        config()->set('services.unipile_pacing.outreach_lead_stagger_seconds', 60);

        $user = $this->userWithOrg();
        $this->connectLinkedIn($user);

        $campaign = V2OutreachCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'name' => 'Keep invite wait',
            'status' => 'running',
            'node_model' => [
                ['key' => 1, 'type' => 'action', 'channel' => 'linkedin', 'action' => 'send_invite', 'label' => 'Send Invite'],
            ],
        ]);

        $lead = V2OutreachLead::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'full_name' => 'Waiting Lead',
            'status' => 'running',
        ]);

        $inviteWaitUntil = now()->addHours(6);
        V2OutreachLeadProgress::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'outreach_lead_id' => $lead->id,
            'current_node_key' => 1,
            'next_node_key' => 2,
            'run_status' => 1,
            'channel_state' => [],
            'next_run_at' => $inviteWaitUntil,
        ]);

        app(OutreachRunDispatcher::class)->dispatch($campaign->fresh(), (int) $user->current_organization_id);

        $progress = V2OutreachLeadProgress::query()->where('outreach_lead_id', $lead->id)->first();
        $this->assertNotNull($progress?->next_run_at);
        $this->assertSame(
            $inviteWaitUntil->getTimestamp(),
            $progress->next_run_at->getTimestamp(),
            'Existing invite-wait next_run_at must not be replaced by launch stagger',
        );
    }

    private function userWithOrg(): User
    {
        $user = User::factory()->create();
        $organization = V2Organization::query()->create([
            'name' => 'Dispatch Org',
            'slug' => 'dispatch-org-'.$user->id,
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

    private function connectLinkedIn(User $user): void
    {
        V2IntegrationAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_account_id' => 'li-'.$user->id,
            'status' => 'active',
            'display_name' => 'Test LinkedIn',
        ]);
    }
}

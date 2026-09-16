<?php

namespace Tests\Unit\V2\Outreach;

use App\Jobs\V2\ProcessOutreachLeadJob;
use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLead;
use App\Models\V2OutreachLeadProgress;
use App\V2\Outreach\OutreachDueLeadDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Mirrors production stuck state: some leads waiting on Invite Accepted? with a future
 * next_run_at, and many Send Invite orphans with null next_run_at after Redis loss.
 */
class OutreachDueLeadDispatcherOrphanWakeTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_due_wakes_send_invite_orphans_without_clobbering_invite_waits(): void
    {
        Queue::fake([ProcessOutreachLeadJob::class]);
        config()->set('services.unipile_pacing.outreach_lead_stagger_seconds', 60);

        $user = $this->userWithOrg();
        $campaign = V2OutreachCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'name' => 'SEO stuck campaign',
            'status' => 'running',
            'node_model' => [
                ['key' => 1, 'type' => 'action', 'channel' => 'linkedin', 'action' => 'send_invite', 'label' => 'Send Invite'],
                ['key' => 2, 'type' => 'condition', 'channel' => 'linkedin', 'condition' => 'invite_accepted', 'label' => 'Invite Accepted?'],
            ],
        ]);

        $inviteWaitUntil = now()->addHours(6);
        for ($i = 0; $i < 3; $i++) {
            $lead = V2OutreachLead::query()->create([
                'outreach_campaign_id' => $campaign->id,
                'full_name' => "Waiting {$i}",
                'status' => 'running',
            ]);
            V2OutreachLeadProgress::query()->create([
                'outreach_campaign_id' => $campaign->id,
                'outreach_lead_id' => $lead->id,
                'current_node_key' => 1,
                'next_node_key' => 2,
                'run_status' => 1,
                'channel_state' => [],
                'next_run_at' => $inviteWaitUntil,
            ]);
        }

        $orphanIds = [];
        for ($i = 0; $i < 5; $i++) {
            $lead = V2OutreachLead::query()->create([
                'outreach_campaign_id' => $campaign->id,
                'full_name' => "Orphan {$i}",
                'status' => 'pending',
            ]);
            $orphanIds[] = $lead->id;
            V2OutreachLeadProgress::query()->create([
                'outreach_campaign_id' => $campaign->id,
                'outreach_lead_id' => $lead->id,
                'current_node_key' => 0,
                'next_node_key' => 1,
                'run_status' => 0,
                'channel_state' => [],
                'next_run_at' => null,
            ]);
        }

        $result = app(OutreachDueLeadDispatcher::class)->dispatchDue(100, false);

        $this->assertGreaterThanOrEqual(5, $result['dispatched']);

        foreach ($orphanIds as $leadId) {
            $progress = V2OutreachLeadProgress::query()->where('outreach_lead_id', $leadId)->first();
            $this->assertNotNull($progress?->next_run_at, "Orphan lead {$leadId} must get a durable next_run_at");
        }

        $waitingStill = V2OutreachLeadProgress::query()
            ->where('outreach_campaign_id', $campaign->id)
            ->where('next_node_key', 2)
            ->get();
        foreach ($waitingStill as $progress) {
            $this->assertSame(
                $inviteWaitUntil->getTimestamp(),
                $progress->next_run_at->getTimestamp(),
                'Invite-accepted wait next_run_at must stay intact without --force',
            );
        }

        Queue::assertPushed(ProcessOutreachLeadJob::class, function (ProcessOutreachLeadJob $job) use ($orphanIds) {
            return in_array($job->outreachLeadId, $orphanIds, true);
        });
    }

    private function userWithOrg(): User
    {
        $user = User::factory()->create();
        $organization = V2Organization::query()->create([
            'name' => 'Orphan Wake Org',
            'slug' => 'orphan-wake-org-'.$user->id,
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

<?php

namespace Tests\Unit\V2\Support;

use App\Jobs\V2\ContinueWorkflowRunJob;
use App\Jobs\V2\ProcessOutreachLeadJob;
use App\Jobs\V2\PublishV2ContentPostJob;
use App\Jobs\V2\SyncOutreachLeadsAndRunJob;
use App\Models\AiWorkflowRun;
use App\Models\User;
use App\Models\V2ContentPost;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLead;
use App\Models\V2OutreachLeadProgress;
use App\V2\Support\QueueRefillFromDatabaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class QueueRefillFromDatabaseServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_refill_requeues_due_outreach_orphans_and_scheduled_posts(): void
    {
        Queue::fake([
            ProcessOutreachLeadJob::class,
            PublishV2ContentPostJob::class,
            ContinueWorkflowRunJob::class,
            SyncOutreachLeadsAndRunJob::class,
        ]);

        $user = $this->userWithOrg();

        $campaign = V2OutreachCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'name' => 'Refill campaign',
            'status' => 'running',
            'node_model' => [
                ['key' => 1, 'type' => 'action', 'channel' => 'linkedin', 'action' => 'send_invite'],
            ],
        ]);

        $lead = V2OutreachLead::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'full_name' => 'Orphan Lead',
            'status' => 'pending',
        ]);

        V2OutreachLeadProgress::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'outreach_lead_id' => $lead->id,
            'current_node_key' => 0,
            'next_node_key' => 1,
            'run_status' => 0,
            'channel_state' => [],
            'next_run_at' => null,
        ]);

        V2ContentPost::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'provider' => 'linkedin',
            'content' => 'Hello world',
            'status' => 'scheduled',
            'scheduled_at' => now()->subMinute(),
        ]);

        $workflow = AiWorkflowRun::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'agent' => 'sales_manager',
            'goal' => 'refill test',
            'status' => 'running',
            'meta' => [],
        ]);
        AiWorkflowRun::query()->whereKey($workflow->id)->update(['updated_at' => now()->subMinutes(5)]);

        $preparing = V2OutreachCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'name' => 'Stuck preparing',
            'status' => 'preparing',
            'node_model' => [],
        ]);
        V2OutreachCampaign::query()->whereKey($preparing->id)->update(['updated_at' => now()->subMinutes(10)]);

        $summary = app(QueueRefillFromDatabaseService::class)->refill(50, false);

        $this->assertGreaterThanOrEqual(1, $summary['outreach_leads']);
        $this->assertGreaterThanOrEqual(1, $summary['content_posts']);
        $this->assertGreaterThanOrEqual(1, $summary['workflows']);
        $this->assertGreaterThanOrEqual(1, $summary['preparing_outreach']);

        Queue::assertPushed(ProcessOutreachLeadJob::class);
        Queue::assertPushed(PublishV2ContentPostJob::class);
        Queue::assertPushed(ContinueWorkflowRunJob::class);
        Queue::assertPushed(SyncOutreachLeadsAndRunJob::class);
    }

    private function userWithOrg(): User
    {
        $user = User::factory()->create();
        $organization = V2Organization::query()->create([
            'name' => 'Refill Org',
            'slug' => 'refill-org-'.$user->id,
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

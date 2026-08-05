<?php

namespace Tests\Unit\V2\Support;

use App\Jobs\V2\CleanupDeletedCampaignArtifactsJob;
use App\Jobs\V2\ProcessCampaignLeadJob;
use App\Jobs\V2\ProcessOutreachLeadJob;
use App\Jobs\V2\SyncOutreachLeadsAndRunJob;
use App\Models\User;
use App\Models\V2Campaign;
use App\Models\V2CampaignNodeEvent;
use App\Models\V2CampaignRun;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\Models\V2OutreachCampaign;
use App\V2\Support\DeletedCampaignArtifactCleaner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DeletedCampaignArtifactCleanerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']);
    }

    public function test_purges_outreach_jobs_from_database_queue(): void
    {
        $keepId = 99;
        $deleteId = 42;

        DB::table('jobs')->insert([
            $this->jobRow($this->fakeSerializedJob(ProcessOutreachLeadJob::class, [
                'outreachCampaignId' => $deleteId,
                'outreachLeadId' => 7,
            ])),
            $this->jobRow($this->fakeSerializedJob(SyncOutreachLeadsAndRunJob::class, [
                'outreachCampaignId' => $deleteId,
            ])),
            $this->jobRow($this->fakeSerializedJob(ProcessOutreachLeadJob::class, [
                'outreachCampaignId' => $keepId,
                'outreachLeadId' => 8,
            ])),
        ]);

        $summary = app(DeletedCampaignArtifactCleaner::class)->clean(
            DeletedCampaignArtifactCleaner::KIND_OUTREACH,
            $deleteId,
            1,
        );

        $this->assertSame(2, $summary['jobs_purged']);
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertStringContainsString('i:'.$keepId.';', (string) DB::table('jobs')->value('payload'));
    }

    public function test_deletes_linkedin_campaign_orphans_and_purges_jobs(): void
    {
        $user = $this->userWithOrg();
        $campaign = V2Campaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'name' => 'To delete',
            'sequence_type' => 'lead_gen',
            'status' => 'running',
            'node_model' => [],
        ]);

        V2CampaignNodeEvent::query()->create([
            'campaign_id' => $campaign->id,
            'status' => 'started',
            'message' => 'hello',
            'executed_at' => now(),
        ]);

        $run = V2CampaignRun::query()->create([
            'user_id' => $user->id,
            'legacy_campaign_id' => $campaign->id,
            'status' => 'queued',
        ]);

        DB::table('jobs')->insert([
            $this->jobRow($this->fakeSerializedJob(ProcessCampaignLeadJob::class, [
                'campaignId' => $campaign->id,
                'campaignLeadId' => 1,
            ])),
        ]);

        $campaignId = (int) $campaign->id;
        $runId = (int) $run->id;
        $campaign->delete();

        $summary = app(DeletedCampaignArtifactCleaner::class)->clean(
            DeletedCampaignArtifactCleaner::KIND_CAMPAIGN,
            $campaignId,
            (int) $user->id,
            [$runId],
        );

        $this->assertSame(1, $summary['jobs_purged']);
        $this->assertSame(1, $summary['events_deleted']);
        $this->assertSame(1, $summary['runs_deleted']);
        $this->assertSame(0, V2CampaignNodeEvent::query()->where('campaign_id', $campaignId)->count());
        $this->assertSame(0, V2CampaignRun::query()->where('id', $runId)->count());
    }

    public function test_destroy_outreach_dispatches_cleanup_job(): void
    {
        Queue::fake([CleanupDeletedCampaignArtifactsJob::class]);

        $user = $this->userWithOrg();
        $campaign = V2OutreachCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'name' => 'Running outreach',
            'status' => 'running',
            'node_model' => [],
        ]);

        $this->actingAs($user)
            ->delete('/outreach/'.$campaign->id)
            ->assertRedirect('/outreach');

        $this->assertDatabaseMissing('v2_outreach_campaigns', ['id' => $campaign->id]);

        Queue::assertPushed(CleanupDeletedCampaignArtifactsJob::class, function (CleanupDeletedCampaignArtifactsJob $job) use ($campaign, $user) {
            return $job->kind === DeletedCampaignArtifactCleaner::KIND_OUTREACH
                && $job->deletedCampaignId === (int) $campaign->id
                && $job->userId === (int) $user->id;
        });
    }

    /**
     * @param  array<string, int|string|null>  $props
     */
    private function fakeSerializedJob(string $class, array $props): string
    {
        $serializedProps = '';
        foreach ($props as $name => $value) {
            $serializedProps .= 's:'.strlen($name).':"'.$name.'";i:'.(int) $value.';';
        }

        $command = 'O:'.strlen($class).':"'.$class.'":'.count($props).':{'.$serializedProps.'}';

        return json_encode([
            'uuid' => 'test-'.uniqid(),
            'displayName' => $class,
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => [
                'commandName' => $class,
                'command' => $command,
            ],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function jobRow(string $payload): array
    {
        return [
            'queue' => 'default',
            'payload' => $payload,
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->getTimestamp(),
            'created_at' => now()->getTimestamp(),
        ];
    }

    private function userWithOrg(): User
    {
        $user = User::factory()->create();
        $organization = V2Organization::query()->create([
            'name' => 'Cleanup Org',
            'slug' => 'cleanup-org-'.$user->id,
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

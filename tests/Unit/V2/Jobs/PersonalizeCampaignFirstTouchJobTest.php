<?php

namespace Tests\Unit\V2\Jobs;

use App\Jobs\V2\PersonalizeCampaignFirstTouchJob;
use App\Jobs\V2\ProcessOutreachLeadJob;
use App\Models\User;
use App\Models\V2IntegrationAccount;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLead;
use App\Models\V2OutreachRun;
use App\V2\Ai\Services\CampaignFirstTouchPersonalizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class PersonalizeCampaignFirstTouchJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_personalize_job_starts_outreach_run_after_drafts(): void
    {
        Queue::fake([ProcessOutreachLeadJob::class]);

        $user = $this->userWithOrg();
        $this->connectEmail($user);

        $campaign = V2OutreachCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'name' => 'One-shot email',
            'status' => 'running',
            'node_model' => [
                ['key' => 1, 'type' => 'action', 'channel' => 'email', 'action' => 'send_email', 'label' => 'Send Email'],
            ],
            'meta' => ['ai_personalize_first_touch' => true],
        ]);

        $lead = V2OutreachLead::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'full_name' => 'Phanrise Engineering',
            'email' => 'vickenconcept@gmail.com',
            'status' => 'pending',
        ]);

        $personalizer = Mockery::mock(CampaignFirstTouchPersonalizationService::class);
        $personalizer->shouldReceive('personalizeCampaign')
            ->once()
            ->andReturn(['personalized' => 1, 'skipped' => 0]);
        $this->app->instance(CampaignFirstTouchPersonalizationService::class, $personalizer);

        (new PersonalizeCampaignFirstTouchJob($campaign->id, 40, (int) $user->current_organization_id))
            ->handle(
                $personalizer,
                app(\App\V2\Outreach\OutreachActivityLogger::class),
                app(\App\V2\Outreach\OutreachRunDispatcher::class),
                app(\App\V2\Outreach\OutreachLeadSyncService::class),
            );

        $this->assertDatabaseHas('v2_outreach_runs', [
            'outreach_campaign_id' => $campaign->id,
            'status' => 'running',
        ]);

        Queue::assertPushed(ProcessOutreachLeadJob::class, function (ProcessOutreachLeadJob $job) use ($campaign, $lead) {
            return $job->outreachCampaignId === $campaign->id
                && $job->outreachLeadId === $lead->id;
        });
    }

    public function test_personalize_job_wakes_pending_leads_when_run_already_exists(): void
    {
        Queue::fake([ProcessOutreachLeadJob::class]);

        $user = $this->userWithOrg();
        $this->connectEmail($user);

        $campaign = V2OutreachCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'name' => 'Stuck one-shot',
            'status' => 'running',
            'node_model' => [
                ['key' => 1, 'type' => 'action', 'channel' => 'email', 'action' => 'send_email', 'label' => 'Send Email'],
            ],
            'meta' => ['ai_personalize_first_touch' => true],
        ]);

        $lead = V2OutreachLead::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'full_name' => 'Stuck Lead',
            'email' => 'stuck@example.com',
            'status' => 'pending',
        ]);

        V2OutreachRun::query()->create([
            'user_id' => $user->id,
            'outreach_campaign_id' => $campaign->id,
            'status' => 'running',
            'started_at' => now(),
        ]);

        $personalizer = Mockery::mock(CampaignFirstTouchPersonalizationService::class);
        $personalizer->shouldReceive('personalizeCampaign')
            ->once()
            ->andReturn(['personalized' => 1, 'skipped' => 0]);
        $this->app->instance(CampaignFirstTouchPersonalizationService::class, $personalizer);

        (new PersonalizeCampaignFirstTouchJob($campaign->id, 40, (int) $user->current_organization_id))
            ->handle(
                $personalizer,
                app(\App\V2\Outreach\OutreachActivityLogger::class),
                app(\App\V2\Outreach\OutreachRunDispatcher::class),
                app(\App\V2\Outreach\OutreachLeadSyncService::class),
            );

        Queue::assertPushed(ProcessOutreachLeadJob::class, function (ProcessOutreachLeadJob $job) use ($campaign, $lead) {
            return $job->outreachCampaignId === $campaign->id
                && $job->outreachLeadId === $lead->id;
        });
    }

    private function userWithOrg(): User
    {
        $user = User::factory()->create();
        $organization = V2Organization::query()->create([
            'name' => 'Pers Job Org',
            'slug' => 'pers-job-org-'.$user->id,
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

    private function connectEmail(User $user): void
    {
        V2IntegrationAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'email',
            'provider_account_id' => 'email-test-'.$user->id,
            'status' => 'active',
            'display_name' => 'Test Email',
        ]);
    }
}

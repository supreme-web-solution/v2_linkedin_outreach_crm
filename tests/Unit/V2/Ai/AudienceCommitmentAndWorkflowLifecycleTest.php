<?php

namespace Tests\Unit\V2\Ai;

use App\Models\AiActionApproval;
use App\Models\AiConversation;
use App\Models\AiWorkflowRun;
use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\ActionApprovalService;
use App\V2\Ai\Services\WorkflowLifecycleService;
use App\V2\Ai\Services\WorkflowRunService;
use App\V2\Ai\Support\AudienceCommitment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AudienceCommitmentAndWorkflowLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_bound_count_prefers_this_run_discovery_over_archive_intersection(): void
    {
        $plan = [
            'required_outcome' => 'send_now',
            'measurable_expectations' => ['target_count' => 1],
            'constraints' => ['new_only' => true, 'data_preference' => 'discover_new'],
        ];
        $runMeta = [
            'cumulative_candidate_delta' => 1,
            'discovery_lists' => [
                ['list_hash' => 'search-1', 'total_leads' => 1],
            ],
        ];
        $stateEval = [
            'requested_quantity' => 1,
            'intersection_eligible_count' => 32,
        ];

        $this->assertSame(1, AudienceCommitment::boundCount($plan, $runMeta, $stateEval));
    }

    public function test_quantified_ask_without_named_list_requires_discover_this_run(): void
    {
        $plan = [
            'required_outcome' => 'send_now',
            'measurable_expectations' => ['target_count' => 1],
            'constraints' => [],
        ];

        $this->assertTrue(AudienceCommitment::shouldForceDiscoverThisRun($plan));
        $this->assertFalse(AudienceCommitment::wantsExplicitReuse($plan));
        $this->assertFalse(AudienceCommitment::requiresDiscoverThisRun($plan));

        $forced = array_merge($plan, [
            'constraints' => ['new_only' => true, 'data_preference' => 'discover_new'],
        ]);
        $this->assertTrue(AudienceCommitment::requiresDiscoverThisRun($forced));
    }

    public function test_explicit_reuse_does_not_force_discover_this_run(): void
    {
        $plan = [
            'required_outcome' => 'send_now',
            'measurable_expectations' => ['target_count' => 20],
            'constraints' => [
                'reuse_first' => true,
                'data_preference' => 'reuse_existing_first',
                'audience_ref' => 'SaaS founders',
            ],
        ];

        $this->assertTrue(AudienceCommitment::wantsExplicitReuse($plan));
        $this->assertFalse(AudienceCommitment::shouldForceDiscoverThisRun($plan));
        $this->assertFalse(AudienceCommitment::requiresDiscoverThisRun($plan));
    }

    public function test_reject_cancels_waiting_workflow_run(): void
    {
        [$user, $org, $conversation] = $this->seedUserOrgConversation();

        $run = app(WorkflowRunService::class)->createPlannedRun(
            $user,
            $org->id,
            $conversation,
            ['goal' => 'send now', 'required_outcome' => 'send_now'],
        );
        app(WorkflowRunService::class)->markWaiting($run, 'awaiting_user_approval');
        $run->update([
            'meta' => ['awaiting_approval' => true, 'prepared' => true, 'approval_id' => 0],
        ]);

        $approval = app(ActionApprovalService::class)->createPendingWithoutSupersede(
            $user,
            $org->id,
            'draft_campaign_plan',
            AiToolPermission::Prepare,
            ['type' => 'campaign', 'goal' => 'test', 'target_count' => 1],
            $conversation,
            $run->id,
        );

        app(ActionApprovalService::class)->reject($approval, $user);

        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertSame('rejected', $approval->fresh()->status);
    }

    public function test_mark_rejected_cancels_waiting_status(): void
    {
        [$user, $org, $conversation] = $this->seedUserOrgConversation();
        $run = app(WorkflowRunService::class)->createPlannedRun(
            $user,
            $org->id,
            $conversation,
            ['goal' => 'stuck'],
        );
        app(WorkflowRunService::class)->markWaiting($run, 'awaiting_user_approval');

        app(WorkflowRunService::class)->markRejected($run->fresh());

        $this->assertSame('cancelled', $run->fresh()->status);
    }

    public function test_orphan_cleanup_cancels_waiting_staging_when_no_campaigns(): void
    {
        [$user, $org, $conversation] = $this->seedUserOrgConversation();
        $run = app(WorkflowRunService::class)->createPlannedRun(
            $user,
            $org->id,
            $conversation,
            ['goal' => 'orphan', 'required_outcome' => 'send_now'],
        );
        app(WorkflowRunService::class)->markWaiting($run, 'awaiting_user_approval');
        $run->update(['meta' => ['awaiting_approval' => true, 'prepared' => true]]);

        $cancelled = app(WorkflowLifecycleService::class)
            ->cancelOrphanedStagingWhenNoCampaigns($user, $org->id);

        $this->assertSame(1, $cancelled);
        $this->assertSame('cancelled', $run->fresh()->status);
    }

    public function test_orphan_cleanup_does_not_cancel_running_discovery(): void
    {
        [$user, $org, $conversation] = $this->seedUserOrgConversation();
        $run = app(WorkflowRunService::class)->createPlannedRun(
            $user,
            $org->id,
            $conversation,
            ['goal' => 'finding', 'required_outcome' => 'find_only'],
        );
        app(WorkflowRunService::class)->markRunning($run, 'discover_1');

        $cancelled = app(WorkflowLifecycleService::class)
            ->cancelOrphanedStagingWhenNoCampaigns($user, $org->id);

        $this->assertSame(0, $cancelled);
        $this->assertSame('running', $run->fresh()->status);
    }

    /**
     * @return array{0:User,1:V2Organization,2:AiConversation}
     */
    private function seedUserOrgConversation(): array
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Lifecycle Org',
            'slug' => 'lifecycle-'.uniqid(),
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $user->forceFill(['current_organization_id' => $org->id])->save();
        $conversation = AiConversation::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'channel' => 'web',
            'status' => 'active',
        ]);

        return [$user, $org, $conversation];
    }
}

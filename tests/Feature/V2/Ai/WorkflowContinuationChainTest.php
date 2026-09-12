<?php

namespace Tests\Feature\V2\Ai;

use App\Jobs\V2\ContinueWorkflowRunJob;
use App\Models\SnLeadList;
use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Ai\Services\WorkflowDiscoveryStepHandler;
use App\V2\Ai\Services\WorkflowRuntimeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Ensures discover → prepare continuation is not blocked by job uniqueness.
 */
class WorkflowContinuationChainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_second_continuation_job_runs_after_discovery(): void
    {
        Queue::fake();

        [$user, $org] = $this->userWithOrg();
        SnLeadList::query()->create([
            'user_id' => $user->id,
            'list_hash' => 'saas-chain',
            'name' => 'marketing agency owners',
        ]);

        $mock = Mockery::mock(WorkflowDiscoveryStepHandler::class);
        $mock->shouldReceive('execute')->once()->andReturn([
            'step_type' => 'discover',
            'provider_returned' => 30,
            'list_hash' => 'saas-chain',
            'list_src' => 'sn',
            'list_name' => 'marketing agency owners',
            'platforms_searched' => ['linkedin', 'instagram'],
            'discovery_lists' => [
                ['list_hash' => 'saas-chain', 'list_src' => 'sn', 'primary_channel' => 'linkedin', 'total_leads' => 15],
            ],
        ]);
        $this->app->instance(WorkflowDiscoveryStepHandler::class, $mock);

        $plan = [
            'required_outcome' => 'send_now',
            'goal' => 'outreach',
            'measurable_expectations' => ['target_count' => 30],
            'constraints' => ['target_count' => 30, 'new_only' => true],
            'objective' => ['segment' => 'marketing agency owners'],
        ];

        $runtime = app(WorkflowRuntimeService::class);
        $run = $runtime->start($user, $org->id, $plan);

        $runtime->tick($run->id);

        Queue::assertPushed(ContinueWorkflowRunJob::class, 2);

        (new ContinueWorkflowRunJob($run->id))->handle(app(WorkflowRuntimeService::class));

        $this->assertTrue(
            $run->fresh()->steps()->where('step_key', 'prepare_outreach')->exists()
            || $run->fresh()->status === 'waiting',
        );
    }

    /**
     * @return array{0:User, 1:V2Organization}
     */
    private function userWithOrg(): array
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Chain Org '.uniqid(),
            'slug' => 'chain-'.uniqid(),
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

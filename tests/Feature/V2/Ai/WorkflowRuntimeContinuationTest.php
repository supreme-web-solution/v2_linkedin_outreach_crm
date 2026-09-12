<?php

namespace Tests\Feature\V2\Ai;

use App\Jobs\V2\ContinueWorkflowRunJob;
use App\Models\AiWorkflowStep;
use App\Models\SnLead;
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

class WorkflowRuntimeContinuationTest extends TestCase
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

    public function test_continue_workflow_run_job_delegates_to_tick(): void
    {
        [$user, $org] = $this->userWithOrg();
        $this->seedSaasFounders($user, 37);
        $plan = $this->outreachPlan(100);

        $this->simulateDiscoveryHandler([fn () => null]);

        $run = app(WorkflowRuntimeService::class)->start($user, $org->id, $plan);

        (new ContinueWorkflowRunJob($run->id))->handle(app(WorkflowRuntimeService::class));

        $step = $run->fresh()->steps()->where('step_key', 'discover_1')->first();
        $this->assertNotNull($step);
        $this->assertSame('completed', $step->status);
    }

    public function test_durable_continuation_across_job_boundaries(): void
    {
        [$user, $org] = $this->userWithOrg();
        $list = $this->seedSaasFounders($user, 37);
        $plan = $this->outreachPlan(100, outcome: 'find_only');

        $call = 0;
        $this->simulateDiscoveryHandler([
            function () use (&$call, $user, $list) {
                $call++;
                $this->addFounders($user, $list, 55, startIndex: 5000);
            },
            function () use (&$call, $user, $list) {
                $call++;
                $this->addFounders($user, $list, 8, startIndex: 6000);
            },
        ]);

        $runtime = app(WorkflowRuntimeService::class);
        $run = $runtime->start($user, $org->id, $plan);

        Queue::assertPushed(ContinueWorkflowRunJob::class, fn ($job) => $job->workflowRunId === $run->id);

        $this->runContinuation($run->id);
        $run = $run->fresh();
        $step1 = $run->steps()->where('step_key', 'discover_1')->first();
        $this->assertNotNull($step1);
        $this->assertSame('completed', $step1->status);
        $this->assertSame(63, $step1->arguments['target_count']);

        $this->runContinuation($run->id);
        $step2 = $run->fresh()->steps()->where('step_key', 'discover_2')->first();
        $this->assertNotNull($step2);
        $this->assertSame(8, $step2->arguments['target_count']);

        $this->runContinuation($run->fresh()->id);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame(2, $call);
    }

    public function test_new_only_first_discovery_targets_100(): void
    {
        [$user, $org] = $this->userWithOrg();
        $this->seedSaasFounders($user, 37);
        $plan = $this->outreachPlan(100, newOnly: true);

        $this->simulateDiscoveryHandler([fn () => null]);

        $run = app(WorkflowRuntimeService::class)->start($user, $org->id, $plan);
        $this->runContinuation($run->id);

        $step = $run->fresh()->steps()->where('step_key', 'discover_1')->first();
        $this->assertSame(100, $step->arguments['target_count']);
    }

    public function test_completed_step_not_re_executed_on_retry(): void
    {
        [$user, $org] = $this->userWithOrg();
        $this->seedSaasFounders($user, 37);
        $plan = $this->outreachPlan(100);

        $calls = 0;
        $this->simulateDiscoveryHandler([
            function () use (&$calls) {
                $calls++;
            },
        ]);

        $runtime = app(WorkflowRuntimeService::class);
        $run = $runtime->start($user, $org->id, $plan);
        $this->runContinuation($run->id);

        $step = $run->fresh()->steps()->where('step_key', 'discover_1')->first();
        $this->assertSame('completed', $step->status);

        $this->runContinuation($run->id);
        $this->assertSame(1, $calls);
        $this->assertSame(1, AiWorkflowStep::query()->where('workflow_run_id', $run->id)->where('step_key', 'discover_1')->count());
    }

    public function test_concurrent_tick_does_not_create_duplicate_active_steps(): void
    {
        [$user, $org] = $this->userWithOrg();
        $this->seedSaasFounders($user, 37);
        $plan = $this->outreachPlan(100);

        $calls = 0;
        $this->simulateDiscoveryHandler([
            function () use (&$calls) {
                $calls++;
            },
        ]);

        $runtime = app(WorkflowRuntimeService::class);
        $run = $runtime->start($user, $org->id, $plan);

        AiWorkflowStep::query()->create([
            'workflow_run_id' => $run->id,
            'step_key' => 'discover_1',
            'sequence' => 1,
            'tool_name' => 'discover_prospects',
            'arguments' => ['target_count' => 63],
            'status' => 'running',
            'started_at' => now(),
        ]);

        $this->runContinuation($run->id);

        $discoverSteps = AiWorkflowStep::query()
            ->where('workflow_run_id', $run->id)
            ->where('step_key', 'like', 'discover_%')
            ->count();

        $this->assertSame(1, $discoverSteps);
        $this->assertSame(1, $calls);
    }

    public function test_second_discovery_uses_delta_not_provider_count(): void
    {
        [$user, $org] = $this->userWithOrg();
        $list = $this->seedSaasFounders($user, 37);
        $plan = $this->outreachPlan(100);

        $this->simulateDiscoveryHandler([
            fn () => $this->addFounders($user, $list, 55, startIndex: 7000),
            fn () => $this->addFounders($user, $list, 8, startIndex: 8000),
        ]);

        $runtime = app(WorkflowRuntimeService::class);
        $run = $runtime->start($user, $org->id, $plan);

        $this->runContinuation($run->id);
        $this->runContinuation($run->fresh()->id);

        $step2 = $run->fresh()->steps()->where('step_key', 'discover_2')->first();
        $this->assertNotNull($step2);
        $this->assertSame(8, $step2->arguments['target_count']);
    }

    public function test_max_discovery_attempts_blocks_workflow(): void
    {
        [$user, $org] = $this->userWithOrg();
        $this->seedSaasFounders($user, 22, whatsAppEligible: 22);

        $plan = $this->outreachPlan(100, channel: 'whatsapp');
        $this->simulateDiscoveryHandler(array_fill(0, 6, fn () => null));

        $runtime = app(WorkflowRuntimeService::class);
        $run = $runtime->start($user, $org->id, $plan);

        $blocked = false;
        for ($i = 0; $i < 8; $i++) {
            $tick = $this->runContinuation($run->fresh(['steps'])->id);
            if ($tick['blocked'] ?? false) {
                $blocked = true;
                break;
            }
        }

        $this->assertTrue($blocked);
        $this->assertSame('blocked', $run->fresh()->status);
    }

    public function test_failure_marks_step_failed_not_completed(): void
    {
        [$user, $org] = $this->userWithOrg();
        $this->seedSaasFounders($user, 37);
        $plan = $this->outreachPlan(100);

        $mock = Mockery::mock(WorkflowDiscoveryStepHandler::class);
        $mock->shouldReceive('execute')->once()->andThrow(new \RuntimeException('Provider failed'));
        $this->app->instance(WorkflowDiscoveryStepHandler::class, $mock);

        $runtime = app(WorkflowRuntimeService::class);
        $run = $runtime->start($user, $org->id, $plan);
        $result = $this->runContinuation($run->id);

        $step = $run->fresh()->steps()->where('step_key', 'discover_1')->first();
        $this->assertContains($step->status, ['pending', 'failed']);
        $this->assertFalse($result['completed'] ?? false);
    }

    public function test_tenant_isolation_on_continuation(): void
    {
        [$userA, $orgA] = $this->userWithOrg();
        [$userB, $orgB] = $this->userWithOrg();

        $this->seedSaasFounders($userA, 37);
        $this->seedSaasFounders($userB, 5);

        $runA = app(WorkflowRuntimeService::class)->start($userA, $orgA->id, $this->outreachPlan(100));
        $runB = app(WorkflowRuntimeService::class)->start($userB, $orgB->id, $this->outreachPlan(100));

        $this->assertSame($userA->id, $runA->fresh()->user_id);
        $this->assertSame($userB->id, $runB->fresh()->user_id);
        $this->assertNotSame($runA->organization_id, $runB->organization_id);
    }

    /**
     * Simulate one durable ContinueWorkflowRunJob execution (separate worker cycle).
     *
     * @return array<string, mixed>
     */
    private function runContinuation(int $workflowRunId): array
    {
        return app(WorkflowRuntimeService::class)->tick($workflowRunId);
    }

    /**
     * @param  list<callable>  $handlers
     */
    private function simulateDiscoveryHandler(array $handlers): void
    {
        $index = 0;
        $mock = Mockery::mock(WorkflowDiscoveryStepHandler::class);
        $mock->shouldReceive('execute')->andReturnUsing(function () use ($handlers, &$index) {
            if (isset($handlers[$index])) {
                ($handlers[$index])();
            }
            $index++;

            return [
                'step_type' => 'discover',
                'requested_count' => 0,
                'provider_returned' => 0,
                'saved_reported' => 0,
            ];
        });
        $this->app->instance(WorkflowDiscoveryStepHandler::class, $mock);
    }

    /**
     * @return array<string, mixed>
     */
    private function outreachPlan(
        int $quantity,
        bool $newOnly = false,
        ?string $channel = null,
        string $outcome = 'send_now',
    ): array {
        return [
            'required_outcome' => $outcome,
            'goal' => 'outreach',
            'measurable_expectations' => ['target_count' => $quantity],
            'constraints' => array_filter([
                'target_count' => $quantity,
                'new_only' => $newOnly,
                'preferred_channel' => $channel,
                'target_segment' => 'SaaS founders',
            ]),
            'objective' => ['segment' => 'SaaS founders'],
        ];
    }

    private function seedSaasFounders(User $user, int $count, int $whatsAppEligible = 0): SnLeadList
    {
        $list = SnLeadList::query()->create([
            'user_id' => $user->id,
            'list_hash' => 'saas-'.uniqid(),
            'name' => 'SaaS founders',
        ]);

        $this->addFounders($user, $list, $count, 0, $whatsAppEligible);

        return $list;
    }

    private function addFounders(
        User $user,
        SnLeadList $list,
        int $count,
        int $startIndex = 0,
        int $whatsAppEligible = 0,
    ): void {
        for ($i = 0; $i < $count; $i++) {
            $idx = $startIndex + $i;
            SnLead::query()->create([
                'sn_list_id' => $list->list_hash,
                'first_name' => 'Founder',
                'last_name' => (string) $idx,
                'email' => "founder{$idx}@saas.test",
                'phone' => '+1555'.str_pad((string) $idx, 7, '0', STR_PAD_LEFT),
                'lid' => "lid-{$idx}",
                'whatsapp_provider_id' => $i < $whatsAppEligible
                    ? '1555'.str_pad((string) $idx, 7, '0', STR_PAD_LEFT).'@s.whatsapp.net'
                    : null,
            ]);
        }
    }

    /**
     * @return array{0:User, 1:V2Organization}
     */
    private function userWithOrg(): array
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Continuation Org '.uniqid(),
            'slug' => 'cont-'.uniqid(),
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

<?php

namespace Tests\Feature\V2\Ai;

use App\Models\SnLead;
use App\Models\SnLeadList;
use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Ai\Services\SemanticTurnPlanNormalizer;
use App\V2\Ai\Services\TurnPlanStateEvaluationService;
use App\V2\Ai\Services\WorkflowDiscoveryStepHandler;
use App\V2\Ai\Services\WorkflowPlanBuilderService;
use App\V2\Ai\Services\WorkflowRuntimeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class WorkflowIncrementalDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_more_leads_semantics_set_new_only_and_discover_new(): void
    {
        $normalizer = app(SemanticTurnPlanNormalizer::class);
        $plan = $normalizer->toEnforcementPlan([
            'user_objective' => 'discover_prospects',
            'target_entity' => 'prospects',
            'target_segment' => 'marketing agency owners',
            'quantity' => 10,
            'new_only' => false,
            'exclude_previously_contacted' => false,
            'decision_maker_required' => false,
            'preferred_channel' => null,
            'geography' => null,
            'schedule_hint' => null,
            'data_preference' => 'unspecified',
            'execution_mode' => 'find_and_save',
            'prepare_only' => false,
            'send_requested' => false,
            'delete_requested' => false,
            'requires_clarification' => false,
            'clarification_reason' => null,
            'ambiguous_referent' => null,
            'confidence' => 0.9,
        ], 'just get me more 10 leads and save them, dont reach out to them yet');

        $this->assertTrue($plan['constraints']['new_only'] ?? false);
        $this->assertSame('discover_new', $plan['constraints']['data_preference'] ?? null);
        $this->assertSame('find_only', $plan['required_outcome']);
    }

    public function test_new_only_quota_not_met_when_existing_pool_exceeds_request(): void
    {
        [$user, $org] = $this->userWithOrg();
        $this->seedAgencyOwners($user, 30);

        $plan = [
            'required_outcome' => 'find_only',
            'measurable_expectations' => ['target_count' => 10],
            'constraints' => ['target_count' => 10, 'new_only' => true, 'data_preference' => 'discover_new'],
            'objective' => ['segment' => 'marketing agency owners'],
        ];

        $eval = app(TurnPlanStateEvaluationService::class)->evaluate($user, $org->id, $plan);
        $planner = app(WorkflowPlanBuilderService::class);

        $this->assertTrue($eval['new_only']);
        $this->assertSame(10, $eval['remaining_deficit']);
        $this->assertFalse($planner->isProspectQuotaMet($plan, $eval, []));
        $this->assertSame(10, $planner->effectiveRemainingDiscovery($eval, []));

        $next = $planner->nextStep($plan, $eval, []);
        $this->assertNotNull($next);
        $this->assertSame('discover_1', $next['step_key']);
        $this->assertSame(10, $next['arguments']['target_count']);
    }

    public function test_find_only_workflow_runs_discovery_despite_existing_leads(): void
    {
        Queue::fake();

        [$user, $org] = $this->userWithOrg();
        $this->seedAgencyOwners($user, 30);

        $mock = Mockery::mock(WorkflowDiscoveryStepHandler::class);
        $mock->shouldReceive('execute')->once()->andReturn([
            'step_type' => 'discover',
            'provider_returned' => 10,
            'saved_reported' => 10,
            'list_hash' => 'new-list',
            'list_src' => 'sn',
            'list_name' => 'agency owners (10)',
        ]);
        $this->app->instance(WorkflowDiscoveryStepHandler::class, $mock);

        $plan = [
            'required_outcome' => 'find_only',
            'measurable_expectations' => ['target_count' => 10],
            'constraints' => ['target_count' => 10, 'new_only' => true],
            'objective' => ['segment' => 'marketing agency owners'],
        ];

        $runtime = app(WorkflowRuntimeService::class);
        $run = $runtime->start($user, $org->id, $plan);
        $runtime->tick($run->id);

        $run = $run->fresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame(1, (int) ($run->meta['discovery_attempts'] ?? 0));
        $this->assertSame(10, (int) ($run->meta['cumulative_candidate_delta'] ?? 0));
        $this->assertNotNull($run->steps()->where('step_key', 'discover_1')->first());
    }

    /**
     * @return array{0:User, 1:V2Organization}
     */
    private function userWithOrg(): array
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Incremental Org '.uniqid(),
            'slug' => 'inc-'.uniqid(),
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

    private function seedAgencyOwners(User $user, int $count): SnLeadList
    {
        $list = SnLeadList::query()->create([
            'user_id' => $user->id,
            'list_hash' => 'agency-'.uniqid(),
            'name' => 'marketing agency owners',
        ]);

        for ($i = 0; $i < $count; $i++) {
            SnLead::query()->create([
                'sn_list_id' => $list->list_hash,
                'first_name' => 'Owner',
                'last_name' => (string) $i,
                'email' => "owner{$i}@agency.test",
                'phone' => '+1555'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
                'lid' => "lid-{$i}",
            ]);
        }

        return $list;
    }
}

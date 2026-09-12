<?php

namespace Tests\Feature\V2\Ai;

use App\Models\SnLead;
use App\Models\SnLeadList;
use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLead;
use App\Models\V2OutreachNodeEvent;
use App\V2\Ai\Services\TurnPlanStateEvaluationService;
use App\V2\Ai\Services\WorkflowDiscoveryStepHandler;
use App\V2\Ai\Services\WorkflowPlanBuilderService;
use App\V2\Ai\Services\WorkflowRuntimeService;
use App\V2\Outreach\OutreachExecutionMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class WorkflowRuntimeIntegrationTest extends TestCase
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

    public function test_1_reuse_existing_initial_remaining_discovery_is_63(): void
    {
        [$user, $org] = $this->userWithOrg();
        $this->seedSaasFounders($user, 37);

        $plan = $this->outreachPlan(100, newOnly: false);
        $eval = app(TurnPlanStateEvaluationService::class)->evaluate($user, $org->id, $plan);

        $this->assertSame(37, $eval['existing_eligible_count']);
        $this->assertSame(63, $eval['remaining_discovery']);
    }

    public function test_1_workflow_discovers_remaining_after_simulated_partial_discovery(): void
    {
        [$user, $org] = $this->userWithOrg();
        $list = $this->seedSaasFounders($user, 37);
        $plan = $this->outreachPlan(100, newOnly: false);

        $this->simulateDiscoveryHandler([
            fn () => $this->addFounders($user, $list, 55, startIndex: 1000),
            fn () => $this->addFounders($user, $list, 8, startIndex: 2000),
        ]);

        $runtime = app(WorkflowRuntimeService::class);
        $run = $runtime->start($user, $org->id, $plan);

        $tick1 = $runtime->tick($run->id);
        $this->assertSame('discover_1', $tick1['step']?->step_key);
        $this->assertSame('completed', $tick1['step']?->status);
        $this->assertSame(63, $tick1['step']?->arguments['target_count']);

        $state1 = $tick1['result']['latest_state'] ?? [];
        $this->assertSame(92, (int) ($state1['intersection_eligible_count'] ?? 0));

        $tick2 = $runtime->tick($run->fresh()->id);
        if ($tick2['step'] !== null) {
            $this->assertSame(8, $tick2['step']->arguments['target_count']);
        }
    }

    public function test_2_new_only_requires_100_not_reduced_by_existing_37(): void
    {
        [$user, $org] = $this->userWithOrg();
        $this->seedSaasFounders($user, 37);

        $plan = $this->outreachPlan(100, newOnly: true);
        $eval = app(TurnPlanStateEvaluationService::class)->evaluate($user, $org->id, $plan);

        $this->assertTrue($eval['new_only']);
        $this->assertSame(100, $eval['new_required']);
        $this->assertSame(100, $eval['remaining_discovery']);
    }

    public function test_3_duplicate_discovery_calculates_usable_delta(): void
    {
        [$user, $org] = $this->userWithOrg();
        $list = $this->seedSaasFounders($user, 0);
        $plan = $this->outreachPlan(100, newOnly: true);

        $run = app(WorkflowRuntimeService::class)->start($user, $org->id, $plan);
        $baseline = $run->meta['baseline_state'];

        $this->addFounders($user, $list, 85, startIndex: 3000);
        $after = app(TurnPlanStateEvaluationService::class)->evaluate($user, $org->id, $plan);
        $delta = app(TurnPlanStateEvaluationService::class)->deltaFromBaseline($after, $baseline, $plan);

        $this->assertSame(85, $delta['candidate_delta']);
        $this->assertSame(15, $delta['remaining_requirement']);
    }

    public function test_4_contacted_exclusion_yields_80_eligible_from_100(): void
    {
        [$user, $org] = $this->userWithOrg();
        $list = $this->seedSaasFounders($user, 100);
        $this->markLeadsContacted($user, $org->id, $list, 20);

        $plan = $this->outreachPlan(100, excludeContacted: true);
        $eval = app(TurnPlanStateEvaluationService::class)->evaluate($user, $org->id, $plan);

        $this->assertSame(100, $eval['candidate_count']);
        $this->assertSame(20, $eval['previously_contacted_count']);
        $this->assertSame(80, $eval['intersection_eligible_count']);
        $this->assertSame(20, $eval['remaining_deficit']);
    }

    public function test_5_whatsapp_intersection_is_30_not_40_or_80(): void
    {
        [$user, $org] = $this->userWithOrg();
        $list = $this->seedSaasFounders($user, 100, whatsAppEligible: 40);
        $waLeads = SnLead::query()->where('sn_list_id', $list->list_hash)
            ->whereNotNull('whatsapp_provider_id')
            ->limit(10)
            ->get();
        $nonWaLeads = SnLead::query()->where('sn_list_id', $list->list_hash)
            ->where(function ($q) {
                $q->whereNull('whatsapp_provider_id')->orWhere('whatsapp_provider_id', '');
            })
            ->limit(10)
            ->get();
        $this->markSpecificLeadsContacted($user, $org->id, $waLeads->merge($nonWaLeads));

        $plan = $this->outreachPlan(100, channel: 'whatsapp', excludeContacted: true);
        $eval = app(TurnPlanStateEvaluationService::class)->evaluate($user, $org->id, $plan);

        $this->assertSame(40, $eval['channel_eligible_count']);
        $this->assertSame(30, $eval['intersection_eligible_count']);
        $this->assertSame(70, $eval['remaining_deficit']);
    }

    public function test_6_impossible_outcome_blocks_after_max_attempts(): void
    {
        [$user, $org] = $this->userWithOrg();
        $this->seedSaasFounders($user, 22, whatsAppEligible: 22);

        $plan = $this->outreachPlan(100, channel: 'whatsapp');
        $runtime = app(WorkflowRuntimeService::class);
        $run = $runtime->start($user, $org->id, $plan);

        $this->simulateDiscoveryHandler(array_fill(0, 6, fn () => null));

        $blocked = false;
        for ($i = 0; $i < 6; $i++) {
            $tick = $runtime->tick($run->fresh(['steps'])->id);
            if ($tick['blocked'] ?? false) {
                $blocked = true;
                break;
            }
        }

        $this->assertTrue($blocked);
        $final = $run->fresh();
        $this->assertSame('blocked', $final->status);
        $this->assertSame(22, (int) ($final->result['eligible'] ?? 0));
        $this->assertGreaterThan(0, (int) ($final->result['remaining_deficit'] ?? 0));
    }

    public function test_7_partial_execution_metrics_report_attempted_success_failed(): void
    {
        [$user, $org] = $this->userWithOrg();
        $campaign = V2OutreachCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'Partial',
            'template_type' => 'whatsapp_only',
            'status' => 'active',
            'node_model' => [],
        ]);

        for ($i = 0; $i < 80; $i++) {
            V2OutreachNodeEvent::query()->create([
                'outreach_campaign_id' => $campaign->id,
                'node_key' => 'wa',
                'channel' => 'whatsapp',
                'action' => 'send',
                'status' => 'sent',
                'message' => 'ok',
                'payload' => [],
                'executed_at' => now(),
            ]);
        }
        for ($i = 0; $i < 20; $i++) {
            V2OutreachNodeEvent::query()->create([
                'outreach_campaign_id' => $campaign->id,
                'node_key' => 'wa',
                'channel' => 'whatsapp',
                'action' => 'send',
                'status' => 'failed',
                'message' => 'fail',
                'payload' => [],
                'executed_at' => now(),
            ]);
        }

        $metrics = app(OutreachExecutionMetricsService::class)->forCampaign($campaign);
        $this->assertSame(100, $metrics['attempted']);
        $this->assertSame(80, $metrics['successful']);
        $this->assertSame(20, $metrics['failed']);
    }

    public function test_8_idempotent_workflow_step_does_not_duplicate_side_effects(): void
    {
        [$user, $org] = $this->userWithOrg();
        $plan = $this->outreachPlan(10, newOnly: true);

        $calls = 0;
        $this->simulateDiscoveryHandler([
            function () use (&$calls, $user) {
                $calls++;
                $this->seedSaasFounders($user, 10);

                return null;
            },
        ]);

        $runtime = app(WorkflowRuntimeService::class);
        $run = $runtime->start($user, $org->id, $plan);
        $runtime->tick($run->id);

        $this->assertSame(1, $calls);
        $this->assertSame(1, $run->fresh()->steps()->where('step_key', 'like', 'discover_%')->count());
    }

    public function test_9_tenant_isolation_in_workflow_state(): void
    {
        [$userA, $orgA] = $this->userWithOrg();
        [$userB, $orgB] = $this->userWithOrg();

        $this->seedSaasFounders($userA, 37);
        $this->seedSaasFounders($userB, 5);

        $evalA = app(TurnPlanStateEvaluationService::class)->evaluate($userA, $orgA->id, $this->outreachPlan(100));
        $evalB = app(TurnPlanStateEvaluationService::class)->evaluate($userB, $orgB->id, $this->outreachPlan(100));

        $this->assertSame(37, $evalA['existing_eligible_count']);
        $this->assertSame(5, $evalB['existing_eligible_count']);
    }

    public function test_planner_requests_discover_quantity_from_remaining_not_requested(): void
    {
        [$user, $org] = $this->userWithOrg();
        $this->seedSaasFounders($user, 37);

        $plan = $this->outreachPlan(100);
        $eval = app(TurnPlanStateEvaluationService::class)->evaluate($user, $org->id, $plan);
        $step = app(WorkflowPlanBuilderService::class)->nextStep($plan, $eval, ['discovery_attempts' => 0]);

        $this->assertNotNull($step);
        $this->assertSame(63, $step['arguments']['target_count']);
    }

    /**
     * @param  list<callable>  $handlers
     */
    private function simulateDiscoveryHandler(array $handlers): void
    {
        $index = 0;
        $mock = Mockery::mock(WorkflowDiscoveryStepHandler::class);
        $mock->shouldReceive('execute')->andReturnUsing(function ($user, $plan, $arguments) use ($handlers, &$index) {
            if (isset($handlers[$index])) {
                ($handlers[$index])();
            }
            $index++;
            $target = (int) ($arguments['target_count'] ?? 0);

            return [
                'step_type' => 'discover',
                'requested_count' => $target,
                'provider_returned' => $target,
                'saved_reported' => $target,
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
        bool $excludeContacted = false,
        ?string $channel = null,
    ): array {
        return [
            'required_outcome' => 'send_now',
            'goal' => 'outreach',
            'measurable_expectations' => ['target_count' => $quantity],
            'constraints' => array_filter([
                'target_count' => $quantity,
                'new_only' => $newOnly,
                'exclude_contacted' => $excludeContacted,
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
     * @param  iterable<SnLead>  $leads
     */
    private function markSpecificLeadsContacted(User $user, int $orgId, iterable $leads): void
    {
        $campaign = V2OutreachCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $orgId,
            'name' => 'Prior',
            'template_type' => 'linkedin_only',
            'status' => 'active',
            'node_model' => [],
        ]);

        foreach ($leads as $lead) {
            V2OutreachLead::query()->create([
                'outreach_campaign_id' => $campaign->id,
                'source_list_src' => 'sn',
                'source_record_id' => $lead->id,
                'provider_profile_id' => $lead->lid,
                'email' => $lead->email,
                'phone' => $lead->phone,
                'full_name' => trim($lead->first_name.' '.$lead->last_name),
                'status' => 'running',
                'meta' => [],
            ]);
        }
    }

    private function markLeadsContacted(
        User $user,
        int $orgId,
        SnLeadList $list,
        int $count,
        bool $onlyWhatsApp = false,
    ): void {
        $campaign = V2OutreachCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $orgId,
            'name' => 'Prior',
            'template_type' => 'linkedin_only',
            'status' => 'active',
            'node_model' => [],
        ]);

        $query = SnLead::query()->where('sn_list_id', $list->list_hash);
        if ($onlyWhatsApp) {
            $query->whereNotNull('whatsapp_provider_id')->where('whatsapp_provider_id', '!=', '');
        }

        foreach ($query->limit($count)->get() as $lead) {
            V2OutreachLead::query()->create([
                'outreach_campaign_id' => $campaign->id,
                'source_list_src' => 'sn',
                'source_record_id' => $lead->id,
                'provider_profile_id' => $lead->lid,
                'email' => $lead->email,
                'phone' => $lead->phone,
                'full_name' => trim($lead->first_name.' '.$lead->last_name),
                'status' => 'running',
                'meta' => [],
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
            'name' => 'Workflow Org '.uniqid(),
            'slug' => 'wf-'.uniqid(),
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

<?php

namespace Tests\Unit\V2\Ai;

use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\Models\V2OutreachCampaign;
use App\V2\Ai\Services\PlanChannelIntentService;
use App\V2\Ai\Services\PostExecutionVerifierService;
use App\V2\Ai\Services\ToolPolicyGateService;
use App\V2\Ai\Services\WorkflowRuntimeService;
use App\V2\Ai\Support\SingleRecipientTurnGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SingleRecipientGovernanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_guard_matches_cold_one_shot_for_any_channel(): void
    {
        foreach (['email', 'instagram', 'linkedin', 'whatsapp'] as $channel) {
            $this->assertTrue(SingleRecipientTurnGuard::matches([
                'constraints' => [
                    'cold_one_shot' => true,
                    'preferred_channel' => $channel,
                ],
                'measurable_expectations' => ['target_count' => 1],
            ]), $channel);
        }
    }

    public function test_tool_gate_blocks_discovery_on_cold_one_shot(): void
    {
        $gate = app(ToolPolicyGateService::class);
        $plan = [
            'required_outcome' => 'send_now',
            'side_effect_budget' => 'external_send_allowed',
            'constraints' => [
                'cold_one_shot' => true,
                'preferred_channel' => 'email',
            ],
        ];

        $this->assertFalse($gate->check('discover_prospects', $plan)['allowed']);
        $this->assertFalse($gate->check('propose_strategy', $plan)['allowed']);
        $this->assertTrue($gate->check('draft_cold_outbound', $plan)['allowed']);
    }

    public function test_parallel_discovery_false_for_one_shot_even_without_list_hash(): void
    {
        $intent = app(PlanChannelIntentService::class);

        $this->assertFalse($intent->wantsParallelDiscovery([
            'preferred_channels' => 'Instagram + LinkedIn + Email',
            'one_shot' => true,
            'source' => 'cold_outbound',
            'primary_channel' => 'email',
        ]));
    }

    public function test_workflow_runtime_refuses_single_recipient_start(): void
    {
        [$user, $org] = $this->seedOrg();

        $run = app(WorkflowRuntimeService::class)->start($user, $org->id, [
            'required_outcome' => 'send_now',
            'constraints' => [
                'cold_one_shot' => true,
                'preferred_channel' => 'instagram',
            ],
            'measurable_expectations' => ['target_count' => 1],
            'state_evaluation' => [
                'skipped' => false,
                'requested_quantity' => 1,
                'requires_external_discovery' => true,
                'remaining_discovery' => 1,
            ],
        ]);

        $this->assertNull($run);
    }

    public function test_verifier_flags_channel_mismatch_and_list_discovery_campaign(): void
    {
        [$user, $org] = $this->seedOrg();
        $svc = app(PostExecutionVerifierService::class);
        $before = $svc->snapshot($user, $org->id);

        V2OutreachCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'Phanrise IG spill',
            'status' => 'paused',
            'meta' => [
                'source' => 'workflow discovery',
                'primary_channel' => 'instagram',
                'ai_plan' => [
                    'source' => 'workflow discovery',
                    'primary_channel' => 'instagram',
                    'one_shot' => false,
                ],
            ],
            'node_model' => [
                ['type' => 'action', 'channel' => 'instagram', 'action' => 'send_message'],
            ],
        ]);

        $after = $svc->snapshot($user, $org->id);
        $result = $svc->verify($user, $org->id, [
            'required_outcome' => 'send_now',
            'constraints' => [
                'cold_one_shot' => true,
                'preferred_channel' => 'email',
            ],
            'measurable_expectations' => ['target_count' => 1],
            'workflow_run_id' => 99,
        ], $before, $after);

        $this->assertFalse($result['ok']);
        $blob = strtolower(implode(' ', $result['warnings']));
        $this->assertStringContainsString('workflow', $blob);
        $this->assertStringContainsString('instagram', $blob);
        $this->assertStringContainsString('email', $blob);
    }

    /**
     * @return array{0:User,1:V2Organization}
     */
    private function seedOrg(): array
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Gate Org',
            'slug' => 'gate-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        return [$user, $org];
    }
}

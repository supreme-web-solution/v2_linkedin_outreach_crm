<?php

namespace Tests\Unit\V2\Ai;

use App\Models\AiEmployeeSetting;
use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Ai\Services\SemanticTurnPlanService;
use App\V2\Ai\Services\TurnPlanBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class TurnPlanBuilderServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_rewrites_discovery_criteria_from_icp_not_the_utterance(): void
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Meaning Org',
            'slug' => 'meaning-org-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $settings = AiEmployeeSetting::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'enabled' => true,
            'autonomy_level' => 2,
            'meta' => [
                'stored_icp' => [
                    'icp' => [
                        'summary' => 'Companies that need custom software and AI automation',
                        'decision_maker' => 'CTOs and business owners',
                    ],
                ],
            ],
        ]);

        $planner = Mockery::mock(SemanticTurnPlanService::class);
        $planner->shouldReceive('interpret')->once()->andReturn([
            'semantic' => [
                'target_segment' => 'CTOs and business owners',
                'quantity' => 10,
            ],
            'enforcement' => [
                'goal' => 'discovery',
                'required_outcome' => 'find_only',
                'side_effect_budget' => 'mutate_allowed',
                'desired_operation' => 'find_and_save',
                'objective' => [
                    'entity' => 'prospects',
                    'segment' => 'CTOs and business owners',
                    'quantity' => 10,
                    'criteria' => 'go ahead, just 10',
                ],
                'constraints' => ['target_count' => 10],
                'measurable_expectations' => ['target_count' => 10],
                'semantic' => [
                    'target_segment' => 'CTOs and business owners',
                    'quantity' => 10,
                    'handoff_brief' => 'Find 10 CTOs and business owners who need custom software.',
                    'handoff_query' => 'CTOs and business owners needing custom software and AI automation',
                ],
                'handoff_brief' => 'Find 10 CTOs and business owners who need custom software.',
                'handoff_query' => 'CTOs and business owners needing custom software and AI automation',
            ],
        ]);
        $this->app->instance(SemanticTurnPlanService::class, $planner);

        $plan = app(TurnPlanBuilderService::class)->build(
            $user,
            $org->id,
            'go ahead, just 10',
            $settings,
        );

        $this->assertSame('find_only', $plan['required_outcome']);
        $this->assertStringContainsString('CTOs', (string) ($plan['objective']['criteria'] ?? ''));
        $this->assertStringNotContainsString('go ahead', strtolower((string) ($plan['objective']['criteria'] ?? '')));
        $this->assertSame(
            'Find 10 CTOs and business owners who need custom software.',
            $plan['interpreted_brief'] ?? null,
        );
        $this->assertStringContainsString('custom software', (string) ($plan['objective']['criteria'] ?? ''));
    }
}

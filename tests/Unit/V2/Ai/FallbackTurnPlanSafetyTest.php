<?php

namespace Tests\Unit\V2\Ai;

use App\V2\Ai\Services\FallbackTurnPlanSafetyService;
use Tests\TestCase;

class FallbackTurnPlanSafetyTest extends TestCase
{
    public function test_general_assist_fallback_is_capped_to_read_only(): void
    {
        $service = app(FallbackTurnPlanSafetyService::class);

        $plan = $service->apply([
            'goal' => 'management',
            'required_outcome' => 'general_assist',
            'side_effect_budget' => 'mutate_allowed',
        ], 'llm_unavailable_or_failed');

        $this->assertSame('regex_fallback', $plan['semantic_source']);
        $this->assertTrue($plan['planning_degraded']);
        $this->assertSame('status_only', $plan['required_outcome']);
        $this->assertSame('read_only', $plan['side_effect_budget']);
        $this->assertTrue($plan['fallback_safety_cap']);
    }

    public function test_explicit_delete_fallback_remains_destructive(): void
    {
        $service = app(FallbackTurnPlanSafetyService::class);

        $plan = $service->apply([
            'goal' => 'management',
            'required_outcome' => 'delete_now',
            'side_effect_budget' => 'destructive_allowed',
        ], 'llm_unavailable_or_failed');

        $this->assertSame('delete_now', $plan['required_outcome']);
        $this->assertSame('destructive_allowed', $plan['side_effect_budget']);
        $this->assertArrayNotHasKey('fallback_safety_cap', $plan);
    }

    public function test_find_only_fallback_remains_mutate_allowed(): void
    {
        $service = app(FallbackTurnPlanSafetyService::class);

        $plan = $service->apply([
            'goal' => 'discovery',
            'required_outcome' => 'find_only',
            'side_effect_budget' => 'mutate_allowed',
        ], 'llm_unavailable_or_failed');

        $this->assertSame('find_only', $plan['required_outcome']);
        $this->assertSame('mutate_allowed', $plan['side_effect_budget']);
    }
}

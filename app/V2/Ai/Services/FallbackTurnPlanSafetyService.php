<?php

namespace App\V2\Ai\Services;

/**
 * When semantic LLM planning is unavailable, cap permissive regex fallback plans
 * so outages degrade intelligence — not safety.
 */
class FallbackTurnPlanSafetyService
{
    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    public function apply(array $plan, string $reason): array
    {
        $plan['semantic_source'] = 'regex_fallback';
        $plan['planning_degraded'] = true;
        $plan['planning_fallback_reason'] = $reason;

        $outcome = (string) ($plan['required_outcome'] ?? '');
        $budget = (string) ($plan['side_effect_budget'] ?? '');

        // Regex "general_assist" is too permissive when LLM is down — default to read-only.
        if ($outcome === 'general_assist' && in_array($budget, ['mutate_allowed', 'prepare_only', 'external_send_allowed'], true)) {
            $plan['required_outcome'] = 'status_only';
            $plan['side_effect_budget'] = 'read_only';
            $plan['fallback_safety_cap'] = true;
        }

        return $plan;
    }
}

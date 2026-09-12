<?php

namespace App\V2\Ai\Services;

class ToolPolicyGateService
{
    /**
     * @param  array<string,mixed>|null  $plan
     * @return array{allowed:bool,reason:?string}
     */
    public function check(string $tool, ?array $plan): array
    {
        if (! is_array($plan)) {
            return ['allowed' => true, 'reason' => null];
        }

        $policy = app(ToolPolicyRegistry::class)->policyFor($tool);
        $actionClass = (string) ($policy['action_class'] ?? 'prepare');
        $budget = (string) ($plan['side_effect_budget'] ?? 'mutate_allowed');
        $outcome = (string) ($plan['required_outcome'] ?? '');
        $constraints = is_array($plan['constraints'] ?? null) ? $plan['constraints'] : [];
        $expectations = is_array($plan['measurable_expectations'] ?? null) ? $plan['measurable_expectations'] : [];

        $order = [
            'read' => 0,
            'prepare' => 1,
            'mutate' => 2,
            'external' => 3,
            'destructive' => 4,
        ];
        $budgetMax = match ($budget) {
            'read_only' => 0,
            'prepare_only' => 1,
            'mutate_allowed' => 2,
            'external_send_allowed' => 3,
            'destructive_allowed' => 4,
            default => 2,
        };

        if ($outcome === 'delete_now' && in_array($tool, ['delete_campaign', 'delete_resource'], true)) {
            return ['allowed' => true, 'reason' => null];
        }

        if (in_array($outcome, ['status_only', 'clarify'], true) || $budget === 'read_only') {
            if (($order[$actionClass] ?? 99) > 0) {
                return [
                    'allowed' => false,
                    'reason' => "Tool {$tool} is blocked: this turn is read-only or requires clarification.",
                ];
            }
        }

        if (($order[$actionClass] ?? 99) > $budgetMax) {
            return ['allowed' => false, 'reason' => "Tool {$tool} is blocked for this request (budget: {$budget})."];
        }

        if ($outcome === 'find_only' && in_array($tool, ['propose_strategy', 'draft_campaign_plan', 'activate_outreach_campaign', 'send_inbox_reply', 'delete_campaign', 'delete_resource'], true)) {
            return ['allowed' => false, 'reason' => "Tool {$tool} is blocked: user asked to find/save only."];
        }

        if ($outcome === 'setup_only' && in_array($tool, ['activate_outreach_campaign', 'send_inbox_reply', 'book_meeting'], true)) {
            return ['allowed' => false, 'reason' => "Tool {$tool} is blocked: user asked for setup only, no sending yet."];
        }

        if (($constraints['exclude_contacted'] ?? false) === true
            && $tool === 'save_contacts'
            && (($constraints['target_count'] ?? null) === null)) {
            return ['allowed' => false, 'reason' => 'Please resolve quantity before saving contacts when exclusion constraints are requested.'];
        }

        $targetCount = (int) ($expectations['target_count'] ?? 0);
        if ($targetCount > 0 && $targetCount > 100) {
            return ['allowed' => false, 'reason' => 'Requested quantity exceeds maximum policy limit of 100 per pull.'];
        }

        return ['allowed' => true, 'reason' => null];
    }
}

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
            default => 2,
        };

        if (($order[$actionClass] ?? 99) > $budgetMax) {
            return ['allowed' => false, 'reason' => "Tool {$tool} is blocked for this request (budget: {$budget})."];
        }

        if ($outcome === 'find_only' && in_array($tool, ['propose_strategy', 'draft_campaign_plan', 'activate_outreach_campaign', 'send_inbox_reply', 'delete_campaign', 'delete_resource'], true)) {
            return ['allowed' => false, 'reason' => "Tool {$tool} is blocked: user asked to find/save only."];
        }

        if ($outcome === 'setup_only' && in_array($tool, ['activate_outreach_campaign', 'send_inbox_reply', 'book_meeting'], true)) {
            return ['allowed' => false, 'reason' => "Tool {$tool} is blocked: user asked for setup only, no sending yet."];
        }

        return ['allowed' => true, 'reason' => null];
    }
}

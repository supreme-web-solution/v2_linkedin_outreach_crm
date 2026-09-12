<?php

namespace Tests\Unit\V2\Ai;

use App\V2\Ai\Services\ToolPolicyGateService;
use Tests\TestCase;

class ToolPolicyGateServiceTest extends TestCase
{
    public function test_blocks_campaign_drafting_for_find_only(): void
    {
        $gate = app(ToolPolicyGateService::class);
        $plan = [
            'required_outcome' => 'find_only',
            'side_effect_budget' => 'read_only',
        ];

        $result = $gate->check('draft_campaign_plan', $plan);
        $this->assertFalse($result['allowed']);
    }

    public function test_blocks_external_send_for_setup_only(): void
    {
        $gate = app(ToolPolicyGateService::class);
        $plan = [
            'required_outcome' => 'setup_only',
            'side_effect_budget' => 'prepare_only',
        ];

        $result = $gate->check('send_inbox_reply', $plan);
        $this->assertFalse($result['allowed']);
    }

    public function test_allows_discovery_tool_for_find_only(): void
    {
        $gate = app(ToolPolicyGateService::class);
        $plan = [
            'required_outcome' => 'find_only',
            'side_effect_budget' => 'mutate_allowed',
        ];

        $result = $gate->check('discover_prospects', $plan);
        $this->assertTrue($result['allowed']);
    }

    public function test_allows_delete_campaign_when_destructive_budget_is_set(): void
    {
        $gate = app(ToolPolicyGateService::class);
        $plan = [
            'required_outcome' => 'delete_now',
            'side_effect_budget' => 'destructive_allowed',
        ];

        $result = $gate->check('delete_campaign', $plan);
        $this->assertTrue($result['allowed']);
    }

    public function test_allows_delete_resource_for_delete_now_even_when_budget_is_too_low(): void
    {
        $gate = app(ToolPolicyGateService::class);
        $plan = [
            'required_outcome' => 'delete_now',
            'side_effect_budget' => 'mutate_allowed',
        ];

        $result = $gate->check('delete_resource', $plan);
        $this->assertTrue($result['allowed']);
    }

    public function test_read_only_blocks_all_mutation_tools(): void
    {
        $gate = app(ToolPolicyGateService::class);
        $plan = [
            'required_outcome' => 'status_only',
            'side_effect_budget' => 'read_only',
        ];

        foreach (['discover_prospects', 'draft_campaign_plan', 'save_contacts', 'send_inbox_reply'] as $tool) {
            $this->assertFalse($gate->check($tool, $plan)['allowed'], $tool);
        }

        $this->assertTrue($gate->check('search_prospects', $plan)['allowed']);
        $this->assertTrue($gate->check('search_activity', $plan)['allowed']);
    }

    public function test_clarify_outcome_blocks_execution_tools(): void
    {
        $gate = app(ToolPolicyGateService::class);
        $plan = [
            'required_outcome' => 'clarify',
            'side_effect_budget' => 'read_only',
        ];

        $this->assertFalse($gate->check('delete_campaign', $plan)['allowed']);
        $this->assertFalse($gate->check('activate_outreach_campaign', $plan)['allowed']);
    }

    public function test_blocks_excessive_target_count(): void
    {
        $gate = app(ToolPolicyGateService::class);
        $plan = [
            'required_outcome' => 'send_now',
            'side_effect_budget' => 'external_send_allowed',
            'measurable_expectations' => [
                'target_count' => 250,
            ],
        ];

        $result = $gate->check('discover_prospects', $plan);
        $this->assertFalse($result['allowed']);
    }
}

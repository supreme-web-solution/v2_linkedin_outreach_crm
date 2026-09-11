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
            'side_effect_budget' => 'read_only',
        ];

        $result = $gate->check('discover_prospects', $plan);
        $this->assertTrue($result['allowed']);
    }
}

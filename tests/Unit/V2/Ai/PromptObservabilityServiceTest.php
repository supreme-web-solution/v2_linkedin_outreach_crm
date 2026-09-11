<?php

namespace Tests\Unit\V2\Ai;

use App\V2\Ai\Services\PromptObservabilityService;
use Tests\TestCase;

class PromptObservabilityServiceTest extends TestCase
{
    public function test_classifies_unknown_pre_agent_prompt_when_no_signals_match(): void
    {
        $service = app(PromptObservabilityService::class);

        $status = $service->classifyPreAgentPrompt(
            'make this thing awesome please',
            null,
            [
                'is_informational' => false,
                'is_discovery' => false,
                'is_outreach' => false,
                'is_setup_only' => false,
                'has_pending_approvals' => false,
            ],
        );

        $this->assertSame('unknown_pre_llm', $status);
    }

    public function test_enforces_clarifier_for_empty_or_generic_reply(): void
    {
        $service = app(PromptObservabilityService::class);

        $empty = $service->enforceClarifierFallback('help me', '');
        $this->assertTrue($empty['used_fallback']);
        $this->assertSame('empty_reply', $empty['reason']);
        $this->assertStringContainsString('Can you clarify one thing', $empty['reply']);

        $generic = $service->enforceClarifierFallback('launch it', 'Done.');
        $this->assertTrue($generic['used_fallback']);
        $this->assertSame('generic_reply', $generic['reason']);
        $this->assertStringContainsString('Goal:', $generic['reply']);
    }
}

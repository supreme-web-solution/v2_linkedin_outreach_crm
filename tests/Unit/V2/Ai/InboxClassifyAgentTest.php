<?php

namespace Tests\Unit\V2\Ai;

use App\Ai\Agents\InboxClassifyAgent;
use App\V2\Ai\Services\InboxClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboxClassifyAgentTest extends TestCase
{
    use RefreshDatabase;

    public function test_classify_uses_structured_agent_when_provider_available(): void
    {
        config()->set('ai.providers.openai.key', 'sk-test');
        config()->set('socifusion_ai.model_failover', ['openai' => null]);

        InboxClassifyAgent::fake([
            [
                'intent' => 'meeting_request',
                'buying_signal' => 'hard',
                'asset_preference' => 'meet',
                'priority' => 'hot',
                'stage' => 'qualified',
                'recommended_action' => 'Use book_meeting — send the booking link.',
                'evidence' => ['meeting'],
            ],
        ]);

        $result = app(InboxClassificationService::class)->classify(
            'Yes — can we hop on a call tomorrow afternoon?'
        );

        InboxClassifyAgent::assertPromptedTimes(1);
        $this->assertSame('meeting_request', $result['intent']);
        $this->assertSame('hot', $result['priority']);
        $this->assertSame('laravel_ai', $result['source'] ?? null);
    }

    public function test_opt_out_hard_gate_skips_agent(): void
    {
        config()->set('ai.providers.openai.key', 'sk-test');
        config()->set('socifusion_ai.model_failover', ['openai' => null]);

        InboxClassifyAgent::fake([
            [
                'intent' => 'interested',
                'buying_signal' => 'soft',
                'asset_preference' => null,
                'priority' => 'hot',
                'stage' => 'engaged',
                'recommended_action' => 'should not run',
                'evidence' => ['yes'],
            ],
        ]);

        $result = app(InboxClassificationService::class)->classify('Please unsubscribe me');

        InboxClassifyAgent::assertNeverPrompted();
        $this->assertSame('opt_out', $result['intent']);
    }
}

<?php

namespace Tests\Unit\V2\Ai;

use App\V2\Ai\Services\AiProviderChain;
use Tests\TestCase;

class AiProviderChainTest extends TestCase
{
    public function test_skips_providers_without_keys(): void
    {
        config()->set('ai.providers.openai.key', 'sk-test');
        config()->set('ai.providers.openrouter.key', '');
        config()->set('ai.providers.gemini.key', 'gem-test');
        config()->set('socifusion_ai.model_failover', [
            'openai' => null,
            'openrouter' => 'openai/gpt-4o-mini',
            'gemini' => 'gemini-2.5-flash',
        ]);

        $chain = app(AiProviderChain::class)->forAgent();

        $this->assertSame(['openai', 'gemini'], array_keys($chain));
        $this->assertTrue(app(AiProviderChain::class)->hasFailover());
    }
}

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
        config()->set('ai.providers.anthropic.key', '');
        config()->set('ai.providers.groq.key', '');
        config()->set('socifusion_ai.model_failover', [
            'openai' => null,
            'openrouter' => 'google/gemini-2.5-flash',
            'gemini' => 'gemini-2.5-flash',
            'anthropic' => 'claude-sonnet-4-5',
            'groq' => 'llama-3.3-70b-versatile',
        ]);

        $chain = app(AiProviderChain::class)->forAgent();

        $this->assertSame(['openai', 'gemini'], array_keys($chain));
        $this->assertTrue(app(AiProviderChain::class)->hasFailover());
    }

    public function test_openrouter_openai_model_swaps_to_non_openai_cover_when_openai_present(): void
    {
        config()->set('ai.providers.openai.key', 'sk-test');
        config()->set('ai.providers.openrouter.key', 'or-test');
        config()->set('ai.providers.gemini.key', '');
        config()->set('ai.providers.anthropic.key', '');
        config()->set('ai.providers.groq.key', '');
        config()->set('socifusion_ai.model_failover', [
            'openai' => null,
            'openrouter' => 'openai/gpt-4o-mini',
        ]);
        config()->set('socifusion_ai.openrouter_non_openai_cover', 'google/gemini-2.5-flash');

        $chain = app(AiProviderChain::class)->forAgent();

        $this->assertSame(['openai', 'openrouter'], array_keys($chain));
        $this->assertSame('google/gemini-2.5-flash', $chain['openrouter']);
    }

    public function test_keeps_non_openai_openrouter_model(): void
    {
        config()->set('ai.providers.openai.key', 'sk-test');
        config()->set('ai.providers.openrouter.key', 'or-test');
        config()->set('ai.providers.gemini.key', '');
        config()->set('ai.providers.anthropic.key', '');
        config()->set('ai.providers.groq.key', '');
        config()->set('socifusion_ai.model_failover', [
            'openai' => null,
            'openrouter' => 'anthropic/claude-sonnet-4',
        ]);

        $chain = app(AiProviderChain::class)->forAgent();

        $this->assertSame('anthropic/claude-sonnet-4', $chain['openrouter']);
    }
}

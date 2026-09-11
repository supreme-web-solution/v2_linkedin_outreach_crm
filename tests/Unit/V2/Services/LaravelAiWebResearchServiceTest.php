<?php

namespace Tests\Unit\V2\Services;

use App\V2\Services\LaravelAiWebResearchService;
use Tests\TestCase;

class LaravelAiWebResearchServiceTest extends TestCase
{
    public function test_is_enabled_when_openai_key_present(): void
    {
        config([
            'socifusion_ai.web_research.enabled' => true,
            'socifusion_ai.web_research.provider' => 'openai',
            'ai.providers.openai.key' => 'test-openai-key',
        ]);

        $service = app(LaravelAiWebResearchService::class);

        $this->assertTrue($service->isEnabled());
        $this->assertFalse($service->supportsWebFetch());
    }

    public function test_supports_web_fetch_on_anthropic(): void
    {
        config([
            'socifusion_ai.web_research.enabled' => true,
            'socifusion_ai.web_research.provider' => 'anthropic',
            'ai.providers.anthropic.key' => 'test-anthropic-key',
        ]);

        $service = app(LaravelAiWebResearchService::class);

        $this->assertTrue($service->isEnabled());
        $this->assertTrue($service->supportsWebFetch());
    }

    public function test_disabled_without_provider_key(): void
    {
        config([
            'socifusion_ai.web_research.enabled' => true,
            'socifusion_ai.web_research.provider' => 'anthropic',
            'ai.providers.anthropic.key' => '',
        ]);

        $this->assertFalse(app(LaravelAiWebResearchService::class)->isEnabled());
    }
}

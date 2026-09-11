<?php

namespace Tests\Unit\V2\Services;

use App\V2\Services\WebScrapeChainService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebScrapeChainServiceTest extends TestCase
{
    public function test_uses_jina_when_configured(): void
    {
        config(['ai.providers.jina.key' => 'test-jina']);

        Http::fake([
            'r.jina.ai/*' => Http::response("Title: Jina Page\n\nJina content here.", 200),
        ]);

        $result = app(WebScrapeChainService::class)->scrape('https://example.com');

        $this->assertTrue($result['ok']);
        $this->assertSame('jina', $result['source']);
        $this->assertStringContainsString('Jina content', $result['content']);
    }

    public function test_falls_back_to_http_when_jina_fails(): void
    {
        config(['ai.providers.jina.key' => 'test-jina']);

        Http::preventStrayRequests();
        Http::fake([
            'r.jina.ai/*' => Http::response('error', 500),
            'https://fallback.test/*' => Http::response('<html><title>HTTP Page</title><body><p>Hello world</p></body></html>', 200),
        ]);

        $result = app(WebScrapeChainService::class)->scrape('https://fallback.test/about');

        $this->assertTrue($result['ok']);
        $this->assertSame('http', $result['source']);
        $this->assertStringContainsString('Hello world', $result['content']);
    }
}

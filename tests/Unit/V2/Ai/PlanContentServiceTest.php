<?php

namespace Tests\Unit\V2\Ai;

use App\V2\Ai\Services\LaravelAiJsonService;
use App\V2\Ai\Services\PlanContentService;
use PHPUnit\Framework\TestCase;

class PlanContentServiceTest extends TestCase
{
    public function test_fallback_icp_when_openai_not_configured(): void
    {
        $jsonAi = $this->createMock(LaravelAiJsonService::class);
        $jsonAi->method('isAvailable')->willReturn(false);

        $service = new PlanContentService($jsonAi);
        $icp = $service->buildIcp('LinkedIn automation for agencies', null, ['CompetitorX'], 'US', null);

        $this->assertSame('US', $icp['geography']);
        $this->assertStringContainsString('LinkedIn automation', $icp['summary']);
        $this->assertContains('CompetitorX', $icp['competitors']);
        $this->assertNotEmpty($icp['search_query']);
        $this->assertArrayHasKey('who_we_sell_to', $icp);
        $this->assertArrayHasKey('outreach_angle', $icp);
    }

    public function test_fallback_icp_stays_with_the_owners_market(): void
    {
        $jsonAi = $this->createMock(LaravelAiJsonService::class);
        $jsonAi->method('isAvailable')->willReturn(false);

        $service = new PlanContentService($jsonAi);
        $icp = $service->buildIcp(
            'We install drip irrigation for citrus farms',
            null,
            [],
            'Kenya',
            'Farm managers struggle with water waste in dry season.',
            [],
            ['owner_goal' => 'Book farm visits', 'preferred_channels' => ['instagram', 'whatsapp']],
        );

        $this->assertSame('Kenya', $icp['geography']);
        $this->assertStringContainsString('irrigation', strtolower((string) $icp['summary']));
        $this->assertStringContainsString('irrigation', strtolower((string) $icp['search_query']));
        $this->assertStringNotContainsString('B2B SaaS', (string) $icp['industry']);
        $this->assertStringNotContainsString('VP Sales', (string) $icp['decision_maker']);
        $this->assertSame('Book farm visits', $icp['primary_outcome']);
    }
}

<?php

namespace Tests\Unit\V2\Ai;

use App\V2\Ai\Services\PlanContentService;
use App\V2\Services\OpenAIContentService;
use PHPUnit\Framework\TestCase;

class PlanContentServiceTest extends TestCase
{
    public function test_fallback_icp_when_openai_not_configured(): void
    {
        $openai = $this->createMock(OpenAIContentService::class);
        $openai->method('isConfigured')->willReturn(false);

        $service = new PlanContentService($openai);
        $icp = $service->buildIcp('LinkedIn automation for agencies', null, ['CompetitorX'], 'US', null);

        $this->assertSame('US', $icp['geography']);
        $this->assertStringContainsString('LinkedIn automation', $icp['summary']);
        $this->assertContains('CompetitorX', $icp['competitors']);
    }
}

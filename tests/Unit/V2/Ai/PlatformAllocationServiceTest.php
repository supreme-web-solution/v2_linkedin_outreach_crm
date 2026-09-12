<?php

namespace Tests\Unit\V2\Ai;

use App\V2\Ai\Services\PlatformAllocationService;
use PHPUnit\Framework\TestCase;

class PlatformAllocationServiceTest extends TestCase
{
    public function test_five_splits_evenly_without_linkedin_bias(): void
    {
        $service = new PlatformAllocationService(
            $this->createMock(\App\V2\Outreach\OutreachChannelGuard::class),
            $this->createMock(\App\V2\Integrations\Mindcase\MindcaseClient::class),
        );

        $this->assertSame(
            ['instagram' => 3, 'linkedin' => 2],
            $service->split(5, ['linkedin', 'instagram']),
        );
    }

    public function test_unspecified_default_is_ten(): void
    {
        $this->assertSame(10, PlatformAllocationService::DEFAULT_TOTAL);
    }

    public function test_twenty_balances_evenly_between_linkedin_and_instagram(): void
    {
        $service = new PlatformAllocationService(
            $this->createMock(\App\V2\Outreach\OutreachChannelGuard::class),
            $this->createMock(\App\V2\Integrations\Mindcase\MindcaseClient::class),
        );

        $this->assertSame(
            ['instagram' => 10, 'linkedin' => 10],
            $service->split(20, ['linkedin', 'instagram']),
        );
    }
}

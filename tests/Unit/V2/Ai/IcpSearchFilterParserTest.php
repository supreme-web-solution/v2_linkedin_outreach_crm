<?php

namespace Tests\Unit\V2\Ai;

use App\V2\Ai\Support\IcpSearchFilterParser;
use PHPUnit\Framework\TestCase;

class IcpSearchFilterParserTest extends TestCase
{
    public function test_parses_us_saas_founders_meeting_goal(): void
    {
        $filters = IcpSearchFilterParser::fromGoal('Book 20 meetings with US SaaS founders this month');

        $this->assertSame(20, $filters['target_meetings']);
        $this->assertSame('Founder', $filters['title']);
        $this->assertSame('United States', $filters['location']);
        $this->assertSame(100, $filters['limit']);
        $this->assertStringContainsString('SaaS', $filters['keywords']);
    }

    public function test_uses_explicit_geography_override(): void
    {
        $filters = IcpSearchFilterParser::fromGoal('Laravel agencies', 'Nigeria');

        $this->assertSame('Nigeria', $filters['location']);
    }
}

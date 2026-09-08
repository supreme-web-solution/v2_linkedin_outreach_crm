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

    public function test_strips_socifusion_pitch_and_builds_short_keywords(): void
    {
        $goal = 'Book 15-minute SociFusion demos with high-fit B2B agency founders, sales consultancy owners, '
            .'and B2B SaaS sales/growth leaders. Position SociFusion as the LinkedIn sales command center '
            .'that finds ideal prospects, runs outreach, prevents missed follow-ups.';

        $filters = IcpSearchFilterParser::fromGoal($goal, null, 40);

        $this->assertSame(40, $filters['limit']);
        $this->assertLessThanOrEqual(80, strlen($filters['keywords']));
        $this->assertStringNotContainsString('SociFusion', $filters['keywords']);
        $this->assertStringNotContainsStringIgnoringCase('command center', $filters['keywords']);
        $this->assertTrue(
            str_contains($filters['keywords'], 'B2B')
            || str_contains($filters['keywords'], 'SaaS')
            || str_contains($filters['keywords'], 'agency'),
        );
        $this->assertTrue(in_array($filters['title'] ?? null, ['Founder', 'Owner', 'Head of Sales', 'Head of Growth', null], true));
    }

    public function test_search_variants_include_broader_fallbacks(): void
    {
        $variants = IcpSearchFilterParser::searchVariants(
            'Fetch 40 fresh LinkedIn prospects for B2B SaaS founders',
            null,
            40,
        );

        $this->assertGreaterThanOrEqual(3, count($variants));
        $this->assertSame(40, $variants[0]['limit']);
        // At least one variant has no title (less strict).
        $this->assertTrue(collect($variants)->contains(fn (array $v) => empty($v['title'])));
    }
}

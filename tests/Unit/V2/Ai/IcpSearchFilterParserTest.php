<?php

namespace Tests\Unit\V2\Ai;

use App\V2\Ai\Support\IcpSearchFilterParser;
use Illuminate\Support\Str;
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
        $this->assertTrue(collect($variants)->contains(fn (array $v) => empty($v['title'])));
    }

    public function test_parses_first_degree_and_nigeria(): void
    {
        $filters = IcpSearchFilterParser::fromGoal('Fetch 30 first degree SaaS founders in Nigeria');

        $this->assertSame(['F'], $filters['network_depths']);
        $this->assertSame('Nigeria', $filters['location']);
        $this->assertTrue(IcpSearchFilterParser::isFirstDegreeOnly($filters['network_depths']));
    }

    public function test_explicit_network_degree_override(): void
    {
        $variants = IcpSearchFilterParser::searchVariants(
            'B2B founders',
            'Canada',
            25,
            ['network_degree' => '2nd,3rd'],
        );

        $this->assertSame(['S', 'O'], $variants[0]['network_depths']);
        $this->assertSame('Canada', $variants[0]['location']);
    }

    public function test_person_name_lookup_does_not_fallback_to_b2b_saas(): void
    {
        $this->assertTrue(IcpSearchFilterParser::looksLikePersonLookup('Eleazar Nzerem'));
        $this->assertTrue(IcpSearchFilterParser::looksLikePersonLookup(
            'Eleazar Nzerem LinkedIn connection exact profile'
        ));

        $variants = IcpSearchFilterParser::searchVariants(
            'Eleazar Nzerem LinkedIn connection exact profile',
            null,
            10,
            ['network_depths' => ['F'], 'skip_industry_fallbacks' => true, 'audience_name' => 'eleazar (1)'],
        );

        $keywords = collect($variants)->pluck('keywords')->filter()->implode(' | ');
        $this->assertStringNotContainsString('B2B SaaS', $keywords);
        $this->assertStringNotContainsString('sales agency', $keywords);
        $this->assertTrue(
            collect($variants)->contains(fn (array $v) => str_contains(Str::lower((string) ($v['keywords'] ?? '')), 'eleazar'))
        );
    }

    public function test_icp_volume_search_still_has_industry_fallbacks(): void
    {
        $variants = IcpSearchFilterParser::searchVariants(
            'Fetch 40 fresh LinkedIn prospects for B2B SaaS founders',
            null,
            40,
        );

        $this->assertTrue(
            collect($variants)->contains(fn (array $v) => ($v['keywords'] ?? '') === 'B2B SaaS')
        );
    }
}

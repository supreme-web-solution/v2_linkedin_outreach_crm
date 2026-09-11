<?php

namespace Tests\Unit\V2\Ai;

use App\V2\Ai\Services\ProspectResearchService;
use App\V2\Ai\Services\SingleChannelOutreachService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProspectResearchServiceTest extends TestCase
{
    public function test_extracts_urls_and_skips_social_hosts(): void
    {
        Http::fake([
            'r.jina.ai/*' => Http::response("Title: Agency Site\n\nWe help SaaS companies with outbound.", 200),
        ]);

        $intel = app(ProspectResearchService::class)->research([
            'headline' => 'Agency owner | https://example.com',
            'about' => 'Most clients from referrals https://linkedin.com/in/foo',
        ]);

        $this->assertContains('https://example.com', $intel['urls_found']);
        $this->assertNotEmpty($intel['scraped']);
        $this->assertContains('referrals', $intel['signals']);
    }

    public function test_single_channel_service_maps_primary_channel_to_template(): void
    {
        $svc = app(SingleChannelOutreachService::class);

        $payload = $svc->applyPrimaryChannel([
            'goal' => 'Book meetings',
            'primary_channel' => 'instagram',
        ]);

        $this->assertSame('instagram', $payload['primary_channel']);
        $this->assertTrue($payload['single_channel_only']);
        $this->assertSame('instagram_only', $svc->resolveTemplateType($payload));
    }

    public function test_primary_channel_from_instagram_list(): void
    {
        $svc = app(SingleChannelOutreachService::class);

        $channel = $svc->primaryChannelFromList([
            'list_src' => 'csv',
            'platform' => 'instagram',
            'list_name' => 'IG: coffee',
        ]);

        $this->assertSame('instagram', $channel);
    }

    public function test_primary_channel_from_linkedin_search(): void
    {
        $svc = app(SingleChannelOutreachService::class);

        $channel = $svc->primaryChannelFromList([
            'list_src' => 'sn',
            'origin' => 'linkedin_search',
        ]);

        $this->assertSame('linkedin', $channel);
    }
}

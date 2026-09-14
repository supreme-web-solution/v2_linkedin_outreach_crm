<?php

namespace Tests\Unit\V2\Ai;

use App\Models\AiConversation;
use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Ai\Services\DiscoveryAudienceQualityService;
use App\V2\Ai\Services\PlanChannelIntentService;
use App\V2\Ai\Services\WorkstreamMemoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiscoveryQualityAndMemoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_instagram_filter_rejects_mega_accounts_without_buyer_fit(): void
    {
        $svc = app(DiscoveryAudienceQualityService::class);
        $filtered = $svc->filterInstagramRows([
            [
                'username' => 'mega_brand',
                'fullName' => 'Mega Brand',
                'bio' => 'Official account. Ask me anything.',
                'followers' => 2_500_000,
            ],
            [
                'username' => 'ops_agency_ng',
                'fullName' => 'Ops Agency',
                'bio' => 'Helping SaaS founders with outbound pipeline and sales ops.',
                'followers' => 4200,
            ],
            [
                'username' => 'lifestyle_creator',
                'fullName' => 'Creator',
                'bio' => 'Entertainment · Lifestyle content creator',
                'followers' => 180_000,
            ],
        ], ['saas', 'founders', 'outbound', 'pipeline'], 10);

        $usernames = collect($filtered['kept'])->pluck('username')->all();
        $this->assertContains('ops_agency_ng', $usernames);
        $this->assertNotContains('mega_brand', $usernames);
        $this->assertNotContains('lifestyle_creator', $usernames);
    }

    public function test_instagram_filter_rejects_media_and_event_pages(): void
    {
        $svc = app(DiscoveryAudienceQualityService::class);
        $filtered = $svc->filterInstagramRows([
            [
                'username' => 'startup_media_page',
                'fullName' => 'Startup Media',
                'bio' => 'India’s operator-first startup media brand: decoding founders, funding and unicorns.',
                'followers' => 82000,
            ],
            [
                'username' => 'big_comic_con',
                'fullName' => 'City Comic Con',
                'bio' => 'Tag your photos #comiccon. Questions? Don’t slide into our DMs; email us!',
                'followers' => 400000,
            ],
            [
                'username' => 'saas_ops_co',
                'fullName' => 'SaaS Ops Co',
                'bio' => 'We help B2B SaaS founders fix outbound pipeline and sales ops.',
                'followers' => 3100,
            ],
        ], ['saas', 'founders', 'outbound', 'pipeline', 'b2b'], 10);

        $usernames = collect($filtered['kept'])->pluck('username')->all();
        $this->assertContains('saas_ops_co', $usernames);
        $this->assertNotContains('startup_media_page', $usernames);
        $this->assertNotContains('big_comic_con', $usernames);
        $this->assertFalse($filtered['weak_fit']);
    }

    public function test_instagram_keyword_rejects_garbled_icp_filler(): void
    {
        $keyword = app(PlanChannelIntentService::class)->instagramKeyword(
            [
                'audience' => 'Instagram only: Founder, CEO, CTO, or Director of Operations at global startups,',
                'goal' => 'get 30 leads from instagram only',
            ],
            [
                'who_we_sell_to' => 'startups new needing established companies across various industries looking for software',
                'niches' => ['clinic owners booking more consultations'],
                'industry' => 'Healthcare',
                'decision_maker' => 'Owner',
            ],
        );

        $lower = strtolower($keyword);
        // Workspace niche wins — not a hardcoded SaaS list, not filler soup.
        $this->assertStringContainsString('clinic', $lower);
        $this->assertStringNotContainsString('needing', $lower);
        $this->assertStringNotContainsString('established companies', $lower);
    }

    public function test_instagram_keyword_candidates_are_domain_agnostic_from_icp(): void
    {
        $candidates = app(PlanChannelIntentService::class)->instagramKeywordCandidates(
            ['audience' => 'Founder OR CEO OR CTO at growing companies'],
            [
                'niches' => ['dental clinic owners', 'medspa owners'],
                'industry' => 'Dental',
                'decision_maker' => 'Owner',
            ],
        );

        $joined = strtolower(implode(' | ', $candidates));
        $this->assertStringContainsString('dental', $joined);
        $this->assertStringContainsString('clinic', $joined);
        $this->assertStringNotContainsString('saas', $joined);
        $this->assertGreaterThan(1, count($candidates));
    }

    public function test_compress_uk_saas_still_works_without_hardcoded_prefer_list(): void
    {
        $keyword = app(PlanChannelIntentService::class)->compressBuyerKeyword(
            'Book 20 meetings with US SaaS founders'
        );

        $this->assertSame('saas founders', strtolower($keyword));
    }

    public function test_instagram_keyword_prefers_buyer_niche_over_seller_pitch(): void
    {
        $keyword = app(PlanChannelIntentService::class)->instagramKeyword(
            ['goal' => 'VickenConcepts sells custom software development and AI-powered solutions designed to transform business operations'],
            [
                'who_we_sell_to' => 'agency owners selling B2B services',
                'niches' => ['marketing agencies', 'SaaS agencies'],
            ],
        );

        $this->assertStringContainsString('marketing', strtolower($keyword));
        $this->assertStringNotContainsString('custom software development', strtolower($keyword));
    }

    public function test_workstream_remembers_outreach_hold_on_find_only(): void
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Hold Org',
            'slug' => 'hold-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $conversation = AiConversation::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'channel' => 'web',
            'status' => 'active',
            'title' => 'Hold',
        ]);

        $memory = app(WorkstreamMemoryService::class);
        $memory->rememberFromTurnPlan($conversation, [
            'required_outcome' => 'find_only',
            'interpreted_brief' => 'Find 10 prospects on LinkedIn',
            'constraints' => [],
        ], 'get me 10 other customers, dont reach out to them yet');

        $conversation->refresh();
        $ws = $memory->current($conversation);
        $this->assertTrue((bool) ($ws['outreach_hold'] ?? false));
        $block = $memory->promptBlock($conversation);
        $this->assertStringContainsString('Outreach hold: ON', $block);
    }
}

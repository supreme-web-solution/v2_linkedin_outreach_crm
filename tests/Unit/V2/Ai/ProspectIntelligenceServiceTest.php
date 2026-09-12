<?php

namespace Tests\Unit\V2\Ai;

use App\Models\User;
use App\Models\V2Conversation;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLead;
use App\V2\Ai\Services\ConversionStageService;
use App\V2\Ai\Services\ProspectIntelligenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProspectIntelligenceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_inbound_scrapes_urls_and_advances_stage(): void
    {
        Http::fake([
            'r.jina.ai/*' => Http::response("Title: Agency Site\n\nWe help SaaS companies with outbound.", 200),
            '*' => Http::response('<html><title>Fallback</title><body>content</body></html>', 200),
        ]);

        config(['ai.providers.jina.key' => 'test-jina']);

        [$user, $lead, $conversation] = $this->fixtures();

        $dossier = app(ProspectIntelligenceService::class)->processInbound(
            $lead,
            $conversation,
            'Check our site https://example.com — we need help with outbound',
        );

        $this->assertSame(ConversionStageService::STAGE_QUALIFYING, $dossier['conversion_stage']);
        $this->assertNotEmpty($dossier['scraped_pages']);
        $this->assertNotEmpty($dossier['conversation_facts']);
    }

    public function test_inbound_with_url_still_captures_identity_and_prose_facts(): void
    {
        Http::fake([
            'r.jina.ai/*' => Http::response("Title: Phanrise\n\nConstruction Trust Platform for builders.", 200),
            '*' => Http::response('<html><body>fallback</body></html>', 200),
        ]);
        config(['ai.providers.jina.key' => 'test-jina']);

        [$user, $lead, $conversation] = $this->fixtures();

        $body = 'get me tailored stuff https://engr.phanrise.com/ you can look me up, i am william victor from Nigeria';
        $dossier = app(ProspectIntelligenceService::class)->processInbound($lead, $conversation, $body);

        $this->assertSame('William Victor', $dossier['identity']['preferred_name'] ?? null);
        $this->assertSame('Nigeria', $dossier['identity']['location'] ?? null);
        $this->assertNotEmpty($dossier['scraped_pages']);
        $this->assertNotEmpty($dossier['conversation_facts']);
    }

    public function test_extract_identity_handles_typos(): void
    {
        $service = app(ProspectIntelligenceService::class);
        $identity = $service->extractIdentity('ia m william victor from nigeria');

        $this->assertSame('William Victor', $identity['name']);
        $this->assertSame('Nigeria', $identity['location']);
    }

    /**
     * @return array{0: User, 1: V2OutreachLead, 2: V2Conversation}
     */
    private function fixtures(): array
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Intel Org',
            'slug' => 'intel-org-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $user->forceFill(['current_organization_id' => $org->id])->save();

        $campaign = V2OutreachCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'Intel Campaign',
            'status' => 'running',
            'node_model' => [],
        ]);

        $lead = V2OutreachLead::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'full_name' => 'Sam Rivera',
            'status' => 'replied',
            'meta' => ['company_name' => 'Rivera Labs'],
        ]);

        $conversation = V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_chat_id' => 'chat_intel_1',
            'status' => 'active',
            'meta' => [
                'outreach_lead_id' => $lead->id,
                'outreach_campaign_id' => $campaign->id,
            ],
        ]);

        return [$user, $lead, $conversation];
    }
}

<?php

namespace Tests\Unit\V2\Ai;

use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLead;
use App\V2\Ai\Services\ConversionStageService;
use App\V2\Ai\Services\ProspectMemoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProspectMemoryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_dossier_from_prospect_intelligence(): void
    {
        $lead = $this->leadWithMeta([
            'prospect_intelligence' => [
                'signals' => ['agency', 'outbound'],
                'urls_found' => ['https://example.com'],
                'scraped' => [
                    [
                        'url' => 'https://example.com',
                        'title' => 'Example Co',
                        'excerpt' => 'We run outbound for SaaS agencies.',
                    ],
                ],
                'researched_at' => now()->toIso8601String(),
            ],
        ]);

        $dossier = app(ProspectMemoryService::class)->dossier($lead);

        $this->assertContains('agency', $dossier['signals']);
        $this->assertCount(1, $dossier['scraped_pages']);
        $this->assertSame('https://example.com', $dossier['scraped_pages'][0]['url']);
    }

    public function test_agent_brief_includes_facts_and_stage(): void
    {
        $memory = app(ProspectMemoryService::class);
        $lead = $this->leadWithMeta([]);

        $memory->appendConversationFact($lead, 'We struggle with outbound consistency', 'inbound');
        $memory->setConversionStage($lead->fresh(), ConversionStageService::STAGE_QUALIFYING);

        $brief = $memory->agentBrief($lead->fresh());

        $this->assertStringContainsString('outbound consistency', $brief);
        $this->assertStringContainsString('qualifying', $brief);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function leadWithMeta(array $meta): V2OutreachLead
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Test Org',
            'slug' => 'test-org-'.uniqid(),
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
            'name' => 'Test Campaign',
            'status' => 'running',
            'node_model' => [],
        ]);

        return V2OutreachLead::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'full_name' => 'Jane Doe',
            'headline' => 'Agency Owner',
            'status' => 'replied',
            'meta' => $meta,
        ]);
    }
}

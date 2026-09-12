<?php

namespace Tests\Feature\V2\Ai;

use App\Models\AiConversation;
use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Ai\Services\CommandCenterResearchService;
use App\V2\Ai\Services\ProspectResearchService;
use App\V2\Services\WebScrapeChainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CommandCenterResearchTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_detects_urls_and_research_intent(): void
    {
        $service = app(CommandCenterResearchService::class);

        $this->assertTrue($service->shouldResearch('Check this https://example.com/agency'));
        $this->assertTrue($service->shouldResearch('linkedin.com/in/jane-doe what do you think?'));
        $this->assertTrue($service->shouldResearch('Research Acme Marketing agency'));
        $this->assertFalse($service->shouldResearch('Find 30 agency owners'));
    }

    public function test_enrich_turn_persists_research_on_conversation(): void
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Research Org',
            'slug' => 'research-org',
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
            'title' => 'Command Center',
        ]);

        $mock = Mockery::mock(ProspectResearchService::class);
        $mock->shouldReceive('research')->once()->andReturn([
            'scraped' => [['url' => 'https://example.com', 'title' => 'Acme Agency', 'excerpt' => 'We help B2B agencies scale outbound.']],
            'signals' => ['agency', 'outbound'],
            'researched_at' => now()->toIso8601String(),
        ]);
        $this->app->instance(ProspectResearchService::class, $mock);

        $service = app(CommandCenterResearchService::class);
        $message = 'What do you think of https://example.com for outreach?';
        $enriched = $service->enrichTurn($conversation, $message, $message);

        $this->assertStringContainsString('[Command Center research completed', $enriched);
        $this->assertStringContainsString('Acme Agency', $enriched);

        $fresh = $conversation->fresh();
        $this->assertIsArray($fresh->meta['command_center_research'] ?? null);
        $this->assertCount(1, $fresh->meta['command_center_research']);
    }

    public function test_extracts_bare_linkedin_urls(): void
    {
        $urls = app(CommandCenterResearchService::class)->extractResearchUrls(
            'Look at linkedin.com/in/jane-smith and tell me an opener',
        );

        $this->assertNotEmpty($urls);
        $this->assertStringContainsString('linkedin.com/in/jane-smith', $urls[0]);
    }
}

<?php

namespace Tests\Unit\V2\Ai;

use App\Ai\Tools\DiscoverProspectsTool;
use App\Ai\Tools\DraftCampaignPlanTool;
use App\Ai\Tools\ProposeStrategyTool;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Ai\AgentContext;
use App\V2\Ai\Enums\AiAutonomyLevel;
use App\V2\Ai\Services\DiscoverProspectsService;
use App\V2\Ai\Services\MultiChannelCampaignStagingService;
use App\V2\Ai\Services\PlatformAllocationService;
use App\V2\Ai\Services\UserTurnIntentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use Mockery;
use Tests\TestCase;

/**
 * Locks the production failure from 2026-09-11:
 * user said "find prospect details" (no count, no outreach) but Soci invented 50,
 * searched LinkedIn+Instagram, and auto-created campaigns under Autopilot.
 */
class LiveFindProspectDetailsRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_find_prospect_details_is_linkedin_only_discovery_without_count(): void
    {
        $intent = app(UserTurnIntentService::class);
        $msg = 'find prospect details';

        $this->assertTrue($intent->isDiscoveryOnly($msg));
        $this->assertFalse($intent->isOutreachCommand($msg));
        $this->assertTrue($intent->prefersLinkedInOnlyDiscovery($msg));
        $this->assertFalse($intent->wantsInstagramDiscovery($msg));
        $this->assertNull(app(DiscoverProspectsService::class)->inferCountFromQuery($msg));
    }

    public function test_allocation_summary_never_claims_user_asked_for_default(): void
    {
        $service = new PlatformAllocationService(
            $this->createMock(\App\V2\Outreach\OutreachChannelGuard::class),
            $this->createMock(\App\V2\Integrations\Mindcase\MindcaseClient::class),
        );

        $plan = $service->plan(
            User::factory()->make(['id' => 1]),
            null,
            ['linkedin', 'instagram'],
        );

        $this->assertTrue($plan['used_default']);
        $this->assertSame(10, $plan['planned_total']);
        $this->assertStringContainsString('No count was given', $plan['summary']);
        $this->assertStringNotContainsString('You asked for', $plan['summary']);
        $this->assertStringNotContainsString('50', $plan['summary']);
    }

    public function test_discover_tool_ignores_hallucinated_fifty_and_does_not_stage_campaigns(): void
    {
        [$context] = $this->autopilotContext('find prospect details');

        /** @var array{targetCount:?int, platform:?string}|array{} $captured */
        $captured = [];

        $discover = Mockery::mock(DiscoverProspectsService::class)->makePartial();
        $discover->shouldReceive('discover')
            ->once()
            ->andReturnUsing(function (
                $user = null,
                $query = null,
                $competitors = null,
                $limit = 10,
                $targetCount = null,
                $preferFresh = false,
                $geography = null,
                $networkDegree = null,
                $title = null,
                $company = null,
                $openLink = null,
                $profileUrl = null,
                $platform = 'auto',
            ) use (&$captured) {
                $captured = [
                    'targetCount' => $targetCount,
                    'platform' => $platform,
                ];

                return [
                    'mode' => 'linkedin',
                    'lists' => [],
                    'sample_profiles' => [
                        ['name' => 'Ada Example', 'headline' => 'Founder'],
                    ],
                ];
            });

        $staging = Mockery::mock(MultiChannelCampaignStagingService::class);
        $staging->shouldReceive('stage')->never();

        $this->app->instance(DiscoverProspectsService::class, $discover);
        $this->app->instance(MultiChannelCampaignStagingService::class, $staging);

        $tool = new DiscoverProspectsTool($context);
        $method = new \ReflectionMethod(DiscoverProspectsTool::class, 'run');
        $method->setAccessible(true);

        // Exact live-shaped LLM tool args: invented 50 + auto platform + rewritten query.
        $result = $method->invoke($tool, new Request([
            'query' => 'B2B SaaS and technology companies needing sales automation and start outreach',
            'platform' => 'auto',
            'target_count' => 50,
            'prefer_fresh' => true,
        ]));

        $this->assertSame('linkedin', $captured['platform'] ?? null);
        $this->assertArrayHasKey('targetCount', $captured);
        $this->assertNull($captured['targetCount']);
        $this->assertTrue((bool) ($result['discovery_only'] ?? false));
        $this->assertArrayNotHasKey('staged_campaigns', $result);
        $this->assertStringContainsString('no outreach', strtolower((string) ($result['instruction'] ?? '')));
    }

    public function test_propose_and_draft_refuse_find_only_even_on_autopilot(): void
    {
        [$context] = $this->autopilotContext('find prospect details');

        $propose = new ProposeStrategyTool($context);
        $draft = new DraftCampaignPlanTool($context);

        $proposeRun = new \ReflectionMethod(ProposeStrategyTool::class, 'run');
        $proposeRun->setAccessible(true);
        $draftRun = new \ReflectionMethod(DraftCampaignPlanTool::class, 'run');
        $draftRun->setAccessible(true);

        $proposeResult = $proposeRun->invoke($propose, new Request([
            'goal' => 'Find B2B SaaS prospects and build campaigns',
            'target_count' => 50,
        ]));
        $draftResult = $draftRun->invoke($draft, new Request([
            'goal' => 'Find B2B SaaS prospects and build campaigns',
            'audience' => 'B2B SaaS founders',
            'target_count' => 50,
            'channels' => 'LinkedIn + Instagram',
        ]));

        $this->assertTrue((bool) ($proposeResult['blocked'] ?? false));
        $this->assertTrue((bool) ($proposeResult['discovery_only'] ?? false));
        $this->assertTrue((bool) ($draftResult['blocked'] ?? false));
        $this->assertTrue((bool) ($draftResult['discovery_only'] ?? false));
    }

    /**
     * @return array{0: AgentContext}
     */
    private function autopilotContext(string $userMessage): array
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Live Regression Org',
            'slug' => 'live-reg-'.uniqid(),
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
            'channel' => 'command_center',
            'status' => 'open',
            'title' => 'Command Center',
        ]);

        AiMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $userMessage,
        ]);

        $settings = app(\App\V2\Ai\Services\AiEmployeeSettingsService::class)->for($user, $org->id);
        $settings->autonomy_level = AiAutonomyLevel::Autopilot->value;
        $settings->save();

        return [new AgentContext(
            user: $user,
            organizationId: $org->id,
            settings: $settings->fresh(),
            conversation: $conversation,
            channel: 'web',
        )];
    }
}

<?php

namespace Tests\Unit\V2\Ai;

use App\Ai\Agents\OutboundDraftQualityAgent;
use App\Models\AiActionApproval;
use App\Models\AiConversation;
use App\Models\User;
use App\Models\V2Conversation;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Ai\Services\CommandCenterService;
use App\V2\Ai\Services\OneShotOutboundCommandCenterService;
use App\V2\Ai\Services\OutboundDraftQualityService;
use App\V2\Ai\Services\OutboundMessageComposerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Phase A: research gate, identity preflight, quality judge, approval-card evidence.
 * Domain-agnostic — no brand/phrase hardcoding.
 */
class ColdOutboundPhaseAGatesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_thin_research_url_blocks_staging(): void
    {
        [$user, $org, $conversation] = $this->seedConversation();

        $this->mock(OutboundMessageComposerService::class, function ($mock) {
            $mock->shouldReceive('researchIsSubstantial')->andReturn(false);
            $mock->shouldReceive('researchUrl')->once()->andReturn("Title: Thin\nURL: https://example.com\nHi");
            $mock->shouldReceive('compose')->never();
        });

        $this->mock(\App\V2\Ai\Services\SemanticTurnPlanService::class, function ($mock) {
            $mock->shouldReceive('interpret')->once()->andReturn([
                'semantic' => [
                    'cold_one_shot' => true,
                    'preferred_channel' => 'email',
                    'handoff_brief' => 'Research then email hello@example.com',
                ],
                'enforcement' => [],
            ]);
        });

        $result = app(OneShotOutboundCommandCenterService::class)->tryHandle(
            $user,
            $org->id,
            $conversation,
            'check https://example.com then email hello@example.com',
            'web',
        );

        $this->assertTrue($result['handled'] ?? false);
        $this->assertNull($result['approval'] ?? null);
        $this->assertStringContainsString('could not read enough', strtolower((string) ($result['reply'] ?? '')));
        $this->assertStringContainsString('will not stage', strtolower((string) ($result['reply'] ?? '')));
    }

    public function test_pending_same_recipient_reuses_approval(): void
    {
        [$user, $org, $conversation] = $this->seedConversation();

        $pending = AiActionApproval::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'conversation_id' => $conversation->id,
            'tool' => 'draft_campaign_plan',
            'permission' => 'prepare',
            'status' => 'pending',
            'payload' => [
                'source' => 'cold_outbound',
                'one_shot' => true,
                'primary_channel' => 'email',
                'contact_email' => 'hello@example.com',
                'audience' => 'hello@example.com',
                'message' => 'Prior pending draft with a clear ask.',
                'subject' => 'Prior',
                'research_url' => 'https://example.com/about',
                'research_facts' => ['Builds a shipping platform for ops teams'],
                'quality' => ['score' => 0.8, 'grounded' => true, 'not_generic' => true, 'summary' => 'Ready'],
            ],
        ]);

        $this->mock(OutboundMessageComposerService::class, function ($mock) {
            $mock->shouldReceive('compose')->never();
            $mock->shouldReceive('researchUrl')->never();
        });

        $this->mock(\App\V2\Ai\Services\SemanticTurnPlanService::class, function ($mock) {
            $mock->shouldReceive('interpret')->once()->andReturn([
                'semantic' => [
                    'cold_one_shot' => true,
                    'preferred_channel' => 'email',
                    'handoff_brief' => 'Email hello@example.com',
                ],
                'enforcement' => [],
            ]);
        });

        $result = app(OneShotOutboundCommandCenterService::class)->tryHandle(
            $user,
            $org->id,
            $conversation,
            'email hello@example.com a quick note',
            'web',
        );

        $this->assertTrue($result['handled'] ?? false);
        $this->assertSame($pending->id, $result['approval']?->id);
        $this->assertStringContainsString('already exists', (string) ($result['reply'] ?? ''));
        $this->assertStringContainsString('LAUNCH '.$pending->id, (string) ($result['reply'] ?? ''));
    }

    public function test_existing_inbox_thread_suggests_draft_reply(): void
    {
        [$user, $org, $conversation] = $this->seedConversation();

        $inbox = V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'email',
            'provider_chat_id' => 'thread-'.uniqid(),
            'meta' => ['email' => 'hello@example.com', 'contact_email' => 'hello@example.com'],
        ]);

        $this->mock(OutboundMessageComposerService::class, function ($mock) {
            $mock->shouldReceive('compose')->never();
        });

        $this->mock(\App\V2\Ai\Services\SemanticTurnPlanService::class, function ($mock) {
            $mock->shouldReceive('interpret')->once()->andReturn([
                'semantic' => [
                    'cold_one_shot' => true,
                    'preferred_channel' => 'email',
                    'handoff_brief' => 'Email hello@example.com',
                ],
                'enforcement' => [],
            ]);
        });

        $result = app(OneShotOutboundCommandCenterService::class)->tryHandle(
            $user,
            $org->id,
            $conversation,
            'email hello@example.com about a partnership',
            'web',
        );

        $this->assertTrue($result['handled'] ?? false);
        $this->assertNull($result['approval'] ?? null);
        $this->assertStringContainsString('draft_reply', (string) ($result['reply'] ?? ''));
        $this->assertStringContainsString((string) $inbox->id, (string) ($result['reply'] ?? ''));
    }

    public function test_quality_agent_failure_blocks_staging_after_rewrite(): void
    {
        [$user, $org, $conversation] = $this->seedConversation();

        config()->set('ai.providers.openai.key', 'sk-test');
        config()->set('socifusion_ai.model_failover', ['openai' => null]);

        $research = "Title: Example Co\nURL: https://example.com/about\n".str_repeat('Example Co helps ops teams ship faster. ', 8);
        $this->mock(OutboundMessageComposerService::class, function ($mock) use ($research) {
            $mock->shouldReceive('researchIsSubstantial')->andReturn(true);
            $mock->shouldReceive('researchUrl')->once()->andReturn($research);
            $mock->shouldReceive('compose')->twice()->andReturn([
                'body' => 'Would love to connect and explore synergies sometime.',
                'subject' => 'Hello',
            ]);
        });

        OutboundDraftQualityAgent::fake([
            [
                'pass' => false,
                'score' => 0.2,
                'grounded' => false,
                'not_generic' => false,
                'has_clear_cta' => true,
                'invents_facts' => false,
                'research_claimed_but_missing' => false,
                'block_severity' => 'hard',
                'evidence_used' => [],
                'issues' => ['Draft is reusable fluff with no research observation.'],
                'summary' => 'Too generic',
            ],
            [
                'pass' => false,
                'score' => 0.25,
                'grounded' => false,
                'not_generic' => false,
                'has_clear_cta' => true,
                'invents_facts' => false,
                'research_claimed_but_missing' => false,
                'block_severity' => 'hard',
                'evidence_used' => [],
                'issues' => ['Still not grounded.'],
                'summary' => 'Still too generic',
            ],
        ]);

        $this->mock(\App\V2\Ai\Services\SemanticTurnPlanService::class, function ($mock) {
            $mock->shouldReceive('interpret')->once()->andReturn([
                'semantic' => [
                    'cold_one_shot' => true,
                    'preferred_channel' => 'email',
                    'handoff_brief' => 'Research then email hello@example.com',
                ],
                'enforcement' => [],
            ]);
        });

        $result = app(OneShotOutboundCommandCenterService::class)->tryHandle(
            $user,
            $org->id,
            $conversation,
            'check https://example.com/about then email hello@example.com',
            'web',
        );

        $this->assertTrue($result['handled'] ?? false);
        $this->assertNull($result['approval'] ?? null);
        $this->assertStringContainsString('quality gate', strtolower((string) ($result['reply'] ?? '')));
    }

    public function test_whatsapp_thin_context_advisory_still_stages(): void
    {
        [$user, $org, $conversation] = $this->seedConversation();

        config()->set('ai.providers.openai.key', 'sk-test');
        config()->set('socifusion_ai.model_failover', ['openai' => null]);

        $this->mock(OutboundMessageComposerService::class, function ($mock) {
            $mock->shouldReceive('researchIsSubstantial')->andReturn(false);
            $mock->shouldReceive('researchUrl')->never();
            $mock->shouldReceive('compose')->once()->andReturn([
                'body' => 'Hello — hope you’re doing well. Mind if I say a quick hi?',
                'subject' => null,
            ]);
        });

        OutboundDraftQualityAgent::fake([
            [
                'pass' => false,
                'score' => 0.35,
                'grounded' => true,
                'not_generic' => false,
                'has_clear_cta' => true,
                'invents_facts' => false,
                'research_claimed_but_missing' => false,
                'block_severity' => 'advisory',
                'evidence_used' => [],
                'issues' => ['Short greeting with limited recipient context.'],
                'summary' => 'Thin WhatsApp opener',
            ],
        ]);

        $this->mock(\App\V2\Ai\Services\SemanticTurnPlanService::class, function ($mock) {
            $mock->shouldReceive('interpret')->once()->andReturn([
                'semantic' => [
                    'cold_one_shot' => true,
                    'preferred_channel' => 'whatsapp',
                    'handoff_brief' => 'just send a hello greeting to him',
                ],
                'enforcement' => [],
            ]);
        });

        $result = app(OneShotOutboundCommandCenterService::class)->tryHandle(
            $user,
            $org->id,
            $conversation,
            'whatsapp 08085204156 — just send a hello greeting to him',
            'web',
        );

        $this->assertTrue($result['handled'] ?? false);
        $this->assertNotNull($result['approval'] ?? null);
        $this->assertStringContainsString('advisory', strtolower((string) ($result['reply'] ?? '')));
        $payload = is_array($result['approval']?->payload) ? $result['approval']->payload : [];
        $this->assertSame('whatsapp', $payload['primary_channel'] ?? null);
        $this->assertTrue((bool) ($payload['quality']['advisory'] ?? false));
        $this->assertStringContainsString('Hello', (string) ($payload['message'] ?? ''));
    }

    public function test_invented_facts_still_hard_block_on_whatsapp(): void
    {
        [$user, $org, $conversation] = $this->seedConversation();

        config()->set('ai.providers.openai.key', 'sk-test');
        config()->set('socifusion_ai.model_failover', ['openai' => null]);

        $this->mock(OutboundMessageComposerService::class, function ($mock) {
            $mock->shouldReceive('researchIsSubstantial')->andReturn(false);
            $mock->shouldReceive('compose')->twice()->andReturn([
                'body' => 'Saw your Series B and your Lagos warehouse expansion — free next Tuesday?',
                'subject' => null,
            ]);
        });

        OutboundDraftQualityAgent::fake([
            [
                'pass' => false,
                'score' => 0.1,
                'grounded' => false,
                'not_generic' => true,
                'has_clear_cta' => true,
                'invents_facts' => true,
                'research_claimed_but_missing' => false,
                'block_severity' => 'hard',
                'evidence_used' => [],
                'issues' => ['Invented funding and warehouse details with no research.'],
                'summary' => 'Invented facts',
            ],
            [
                'pass' => false,
                'score' => 0.1,
                'grounded' => false,
                'not_generic' => true,
                'has_clear_cta' => true,
                'invents_facts' => true,
                'research_claimed_but_missing' => false,
                'block_severity' => 'hard',
                'evidence_used' => [],
                'issues' => ['Still invents facts.'],
                'summary' => 'Still invented',
            ],
        ]);

        $this->mock(\App\V2\Ai\Services\SemanticTurnPlanService::class, function ($mock) {
            $mock->shouldReceive('interpret')->once()->andReturn([
                'semantic' => [
                    'cold_one_shot' => true,
                    'preferred_channel' => 'whatsapp',
                    'handoff_brief' => 'WhatsApp 08085204156',
                ],
                'enforcement' => [],
            ]);
        });

        $result = app(OneShotOutboundCommandCenterService::class)->tryHandle(
            $user,
            $org->id,
            $conversation,
            'whatsapp 08085204156 about their Series B',
            'web',
        );

        $this->assertTrue($result['handled'] ?? false);
        $this->assertNull($result['approval'] ?? null);
        $this->assertStringContainsString('quality gate', strtolower((string) ($result['reply'] ?? '')));
    }

    public function test_should_hard_block_helpers_respect_channel_and_severity(): void
    {
        $service = app(OutboundDraftQualityService::class);

        $this->assertFalse($service->shouldHardBlockStaging([
            'pass' => false,
            'source' => 'laravel_ai',
            'block_severity' => 'advisory',
            'invents_facts' => false,
            'research_claimed_but_missing' => false,
        ], 'whatsapp'));

        $this->assertTrue($service->shouldHardBlockStaging([
            'pass' => false,
            'source' => 'laravel_ai',
            'block_severity' => 'hard',
            'invents_facts' => false,
            'research_claimed_but_missing' => false,
        ], 'whatsapp'));

        $this->assertTrue($service->shouldHardBlockStaging([
            'pass' => false,
            'source' => 'laravel_ai',
            'block_severity' => 'advisory',
            'invents_facts' => true,
            'research_claimed_but_missing' => false,
        ], 'whatsapp'));

        $this->assertTrue($service->shouldHardBlockStaging([
            'pass' => false,
            'source' => 'laravel_ai',
            'block_severity' => '',
            'invents_facts' => false,
            'research_claimed_but_missing' => false,
        ], 'email', 'Example Co helps ops teams ship faster every week with clear workflows.', 'https://example.com'));
    }

    public function test_format_plan_card_shows_research_and_quality_for_cold_outbound(): void
    {
        $card = app(CommandCenterService::class)->formatPlanCard([
            'type' => 'campaign',
            'source' => 'cold_outbound',
            'one_shot' => true,
            'goal' => 'One-shot Email to hello@example.com',
            'audience' => 'hello@example.com',
            'primary_channel' => 'email',
            'research_url' => 'https://example.com/about',
            'research_facts' => [
                'Example Co helps ops teams ship faster',
                'Public pricing page mentions mid-market focus',
            ],
            'subject' => 'Quick thought',
            'message' => 'Hi — noticed how Example Co helps ops teams ship faster. Open to a short chat?',
            'quality' => [
                'score' => 0.86,
                'grounded' => true,
                'not_generic' => true,
                'summary' => 'Grounded in research',
            ],
            'pause_on_reply' => true,
        ], 42, 'web');

        $this->assertStringContainsString('Cold outbound — Review & Launch', $card);
        $this->assertStringContainsString('hello@example.com', $card);
        $this->assertStringContainsString('https://example.com/about', $card);
        $this->assertStringContainsString('ops teams ship faster', $card);
        $this->assertStringContainsString('Draft quality:', $card);
        $this->assertStringContainsString('awaiting approval (not sent)', $card);
        $this->assertStringContainsString('Review & Launch', $card);
    }

    public function test_research_fact_bullets_skip_meta_lines(): void
    {
        $bullets = app(OutboundDraftQualityService::class)->researchFactBullets(
            "Title: Example Co\nURL: https://example.com\nAI research notes:\nHelps mid-market ops teams.\nPublic case study on shipping velocity."
        );

        $this->assertNotEmpty($bullets);
        $this->assertStringContainsString('mid-market', $bullets[0]);
        foreach ($bullets as $b) {
            $this->assertDoesNotMatchRegularExpression('/^(URL|Title|AI research notes):/i', $b);
        }
    }

    /**
     * @return array{0: User, 1: V2Organization, 2: AiConversation}
     */
    private function seedConversation(): array
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Phase A Org',
            'slug' => 'phase-a-'.uniqid(),
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
            'title' => 'Phase A',
        ]);

        return [$user, $org, $conversation];
    }
}

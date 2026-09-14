<?php

namespace Tests\Unit\V2\Ai;

use App\Models\AiConversation;
use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\Models\V2OutreachImportLead;
use App\V2\Ai\Services\OneShotOutboundCommandCenterService;
use App\V2\Ai\Services\OutboundDraftQualityService;
use App\V2\Ai\Services\OutboundMessageComposerService;
use App\V2\Ai\Services\UserTurnIntentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Mimics how different users phrase cold outbound (any industry).
 * No workspace-specific brands — identity comes from the utterance.
 */
class ColdOutboundIntentTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_user_mimic_phrasing_maps_to_channel_without_inbox_thread(): void
    {
        $intent = app(UserTurnIntentService::class);

        foreach ($this->userMimicCases() as $case) {
            $message = $case['message'];
            $this->assertTrue(
                $intent->isColdOutboundRequest($message),
                "Should be cold outbound: {$message}"
            );
            $this->assertFalse(
                $intent->isInboxReplyRequest($message),
                "Must not be inbox-reply (no thread required): {$message}"
            );

            $identity = $intent->extractColdOutboundIdentity($message);
            $this->assertSame($case['channel'], $identity['channel'], $message);

            if (isset($case['email'])) {
                $this->assertSame($case['email'], $identity['email'], $message);
            }
            if (isset($case['research_url'])) {
                $this->assertSame($case['research_url'], $identity['research_url'], $message);
            }
            if (isset($case['linkedin_url'])) {
                $this->assertStringContainsString('linkedin.com/in/', (string) $identity['linkedin_url'], $message);
            }
        }
    }

    public function test_equivalent_email_phrasings_share_channel_and_recipient(): void
    {
        $intent = app(UserTurnIntentService::class);
        $canonical = $intent->extractColdOutboundIdentity(
            'email hello@acme.test about a quick intro'
        );

        foreach ([
            'send one email to hello@acme.test',
            'pls email hello@acme.test',
            'get a planed reply for hello@acme.test and email him',
            'draft a message to hello@acme.test then send',
            'write them at hello@acme.test',
        ] as $variant) {
            $this->assertTrue($intent->isColdOutboundRequest($variant), $variant);
            $got = $intent->extractColdOutboundIdentity($variant);
            $this->assertSame('email', $got['channel'], $variant);
            $this->assertSame($canonical['email'], $got['email'], $variant);
        }
    }

    public function test_inbox_contrast_is_not_cold_outbound(): void
    {
        $intent = app(UserTurnIntentService::class);

        foreach ([
            'draft a reply for that email we received from Ada in the inbox',
            'reply to the open inbox thread from Sam',
            'who is in the attention queue — reply to them',
        ] as $message) {
            $this->assertFalse($intent->isColdOutboundRequest($message), $message);
        }
    }

    public function test_recipient_correction_reuses_prior_research_and_draft(): void
    {
        [$user, $org] = $this->userWithOrg();
        $conversation = AiConversation::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'channel' => 'web',
            'status' => 'active',
            'title' => 'Cold outbound',
        ]);

        $priorDraft = 'Hi — I looked at Example Co and liked how you talk about shipping faster. Curious how you handle that today?';
        \App\Models\AiActionApproval::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'conversation_id' => $conversation->id,
            'tool' => 'draft_campaign_plan',
            'permission' => 'prepare',
            'status' => 'executed',
            'payload' => [
                'source' => 'cold_outbound',
                'one_shot' => true,
                'primary_channel' => 'email',
                'contact_email' => 'wrong@example.com',
                'audience' => 'wrong@example.com',
                'message' => $priorDraft,
                'research_url' => 'https://example.com/about',
                'research_notes' => "Title: Example Co\nURL: https://example.com/about\nExample Co helps teams ship products faster.",
            ],
        ]);

        $this->mock(OutboundMessageComposerService::class, function ($mock) use ($priorDraft) {
            $mock->shouldReceive('researchIsSubstantial')->andReturn(true);
            $mock->shouldReceive('researchUrl')->never();
            $mock->shouldReceive('compose')->never();
        });
        $this->mockPassingDraftQuality(['Example Co helps teams ship products faster.']);

        // Semantic planner understands correction from thread meaning — not keyword lists.
        $this->mock(\App\V2\Ai\Services\SemanticTurnPlanService::class, function ($mock) {
            $mock->shouldReceive('interpret')->once()->andReturn([
                'semantic' => [
                    'cold_one_shot' => true,
                    'recipient_correction' => true,
                    'preferred_channel' => 'email',
                    'handoff_brief' => 'Reuse prior research/draft; send the same one-shot to hello@example.com only.',
                    'send_requested' => true,
                    'prepare_only' => false,
                ],
                'enforcement' => [],
            ]);
        });

        // Phrasing that keyword heuristics may miss — LLM must still treat as correction.
        $correction = 'actually that was the wrong address — hello@example.com is the one';

        $result = app(OneShotOutboundCommandCenterService::class)->tryHandle(
            $user,
            $org->id,
            $conversation,
            $correction,
            'web',
        );

        $this->assertNotNull($result);
        $this->assertTrue($result['handled'] ?? false);
        $this->assertStringContainsString('hello@example.com', (string) ($result['reply'] ?? ''));
        $this->assertStringContainsString('Corrected', (string) ($result['reply'] ?? ''));
        $this->assertStringContainsString('example.com/about', (string) ($result['reply'] ?? ''));

        $payload = is_array($result['approval']?->payload) ? $result['approval']->payload : [];
        $this->assertSame('hello@example.com', $payload['contact_email'] ?? null);
        $this->assertSame('https://example.com/about', $payload['research_url'] ?? null);
        $this->assertSame($priorDraft, $payload['message'] ?? null);
        $this->assertTrue((bool) ($payload['recipient_correction'] ?? false));
        $this->assertStringContainsString($priorDraft, (string) ($payload['message'] ?? ''));
    }

    public function test_one_shot_stages_generic_contact_with_optional_site_research(): void
    {
        [$user, $org] = $this->userWithOrg();
        $conversation = AiConversation::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'channel' => 'web',
            'status' => 'active',
            'title' => 'Cold outbound',
        ]);

        $research = "Title: Example Co\nURL: https://example.com/about\nExample Co helps teams ship products faster with construction trust tooling.";
        $body = 'Hi — I looked at Example Co and liked the focus on shipping faster. Open to a quick chat?';
        $this->mock(OutboundMessageComposerService::class, function ($mock) use ($research, $body) {
            $mock->shouldReceive('researchIsSubstantial')->andReturnUsing(fn (string $n) => strlen($n) > 40);
            $mock->shouldReceive('researchUrl')->once()->with('https://example.com/about')->andReturn($research);
            $mock->shouldReceive('compose')->once()->andReturn([
                'body' => $body,
                'subject' => 'Quick thought on Example Co',
            ]);
        });
        $this->mockPassingDraftQuality(['Example Co helps teams ship products faster with construction trust tooling.']);

        $this->mock(\App\V2\Ai\Services\SemanticTurnPlanService::class, function ($mock) {
            $mock->shouldReceive('interpret')->once()->andReturn([
                'semantic' => [
                    'cold_one_shot' => true,
                    'recipient_correction' => false,
                    'preferred_channel' => 'email',
                    'handoff_brief' => 'Research example.com/about then one-shot email hello@example.com.',
                    'send_requested' => true,
                    'prepare_only' => false,
                ],
                'enforcement' => [],
            ]);
        });

        $message = 'check https://example.com/about then email hello@example.com';
        $result = app(OneShotOutboundCommandCenterService::class)->tryHandle(
            $user,
            $org->id,
            $conversation,
            $message,
            'web',
        );

        $this->assertNotNull($result);
        $this->assertTrue($result['handled'] ?? false);
        $this->assertNotNull($result['approval'] ?? null);
        $this->assertStringContainsString('hello@example.com', (string) ($result['reply'] ?? ''));
        $this->assertSame(1, V2OutreachImportLead::query()->where('email', 'hello@example.com')->count());

        $payload = is_array($result['approval']?->payload) ? $result['approval']->payload : [];
        $this->assertTrue((bool) ($payload['one_shot'] ?? false));
        $this->assertSame('email', $payload['primary_channel'] ?? null);
        $this->assertSame(1, (int) ($payload['target_count'] ?? 0));
    }

    public function test_same_recipient_with_research_url_does_not_reuse_generic_draft(): void
    {
        [$user, $org] = $this->userWithOrg();
        $conversation = AiConversation::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'channel' => 'web',
            'status' => 'active',
            'title' => 'Cold outbound',
        ]);

        $generic = "Hi Vicken,\n\nI hope this message finds you well! I wanted to reach out and connect. I've been exploring some interesting ideas related to our industry and would love to hear your thoughts.\n\nLooking forward to your reply!\n\nBest,";
        \App\Models\AiActionApproval::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'conversation_id' => $conversation->id,
            'tool' => 'draft_campaign_plan',
            'permission' => 'prepare',
            'status' => 'executed',
            'payload' => [
                'source' => 'cold_outbound',
                'one_shot' => true,
                'primary_channel' => 'email',
                'contact_email' => 'hello@example.com',
                'audience' => 'hello@example.com',
                'message' => $generic,
                'research_url' => 'https://example.com/about',
                'research_notes' => 'Title: Example Co',
            ],
        ]);

        $research = str_repeat('Example Co builds a construction trust platform for contractors. ', 8);
        $rewritten = "Hi Vicken,\n\nI reviewed Example Co's construction trust platform...\n\nBest regards";
        $this->mock(OutboundMessageComposerService::class, function ($mock) use ($research, $rewritten) {
            $mock->shouldReceive('researchIsSubstantial')->andReturnUsing(fn (string $n) => strlen(trim($n)) >= 120);
            $mock->shouldReceive('researchUrl')->once()->andReturn("Title: Example Co\nURL: https://example.com/about\n".$research);
            $mock->shouldReceive('compose')->once()->andReturn([
                'body' => $rewritten,
                'subject' => 'Idea for Example Co',
            ]);
        });
        $this->mockPassingDraftQuality(['Example Co builds a construction trust platform for contractors.']);

        $this->mock(\App\V2\Ai\Services\SemanticTurnPlanService::class, function ($mock) {
            $mock->shouldReceive('interpret')->once()->andReturn([
                'semantic' => [
                    'cold_one_shot' => true,
                    'recipient_correction' => true,
                    'message_correction' => false,
                    'preferred_channel' => 'email',
                    'handoff_brief' => 'Email hello@example.com using research from example.com/about.',
                    'send_requested' => true,
                ],
                'enforcement' => [],
            ]);
        });

        $result = app(OneShotOutboundCommandCenterService::class)->tryHandle(
            $user,
            $org->id,
            $conversation,
            'email hello@example.com, check https://example.com/about and tailor to his need',
            'web',
        );

        $this->assertNotNull($result);
        $payload = is_array($result['approval']?->payload) ? $result['approval']->payload : [];
        $this->assertFalse((bool) ($payload['recipient_correction'] ?? false));
        $this->assertSame($rewritten, $payload['message'] ?? null);
        $this->assertStringContainsString('construction trust', (string) ($payload['research_notes'] ?? ''));
        $this->assertStringNotContainsString('Corrected recipient', (string) ($result['reply'] ?? ''));
    }

    public function test_vague_rewrite_without_email_reuses_prior_identity_and_rescapes(): void
    {
        [$user, $org] = $this->userWithOrg();
        $conversation = AiConversation::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'channel' => 'web',
            'status' => 'active',
            'title' => 'Cold outbound',
        ]);

        \App\Models\AiActionApproval::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'conversation_id' => $conversation->id,
            'tool' => 'draft_campaign_plan',
            'permission' => 'prepare',
            'status' => 'executed',
            'payload' => [
                'source' => 'cold_outbound',
                'one_shot' => true,
                'primary_channel' => 'email',
                'contact_email' => 'hello@example.com',
                'message' => 'Hi — short vague note.',
                'research_url' => 'https://example.com/about',
                'research_notes' => 'thin',
            ],
        ]);

        $body = "Hi,\n\nI spent time on Example Co's site and how you help construction teams...\n\nBest";
        $this->mock(OutboundMessageComposerService::class, function ($mock) use ($body) {
            $mock->shouldReceive('researchIsSubstantial')->andReturnUsing(fn (string $n) => strlen(trim($n)) >= 120);
            $mock->shouldReceive('researchUrl')->once()->andReturn(
                "Title: Example Co\nURL: https://example.com/about\n".str_repeat('Example Co helps construction teams. ', 10)
            );
            $mock->shouldReceive('compose')->once()->andReturn([
                'body' => $body,
                'subject' => 'Example Co',
            ]);
        });
        $this->mockPassingDraftQuality(['Example Co helps construction teams.']);

        $this->mock(\App\V2\Ai\Services\SemanticTurnPlanService::class, function ($mock) {
            $mock->shouldReceive('interpret')->once()->andReturn([
                'semantic' => [
                    'cold_one_shot' => true,
                    'message_correction' => true,
                    'recipient_correction' => false,
                    'preferred_channel' => 'email',
                    'handoff_brief' => 'Rewrite prior email using the research URL; make it detailed.',
                    'send_requested' => true,
                ],
                'enforcement' => [],
            ]);
        });

        $result = app(OneShotOutboundCommandCenterService::class)->tryHandle(
            $user,
            $org->id,
            $conversation,
            'no you did not even check the url and the email content is too short and vague',
            'web',
        );

        $this->assertNotNull($result);
        $this->assertTrue($result['handled'] ?? false);
        $payload = is_array($result['approval']?->payload) ? $result['approval']->payload : [];
        $this->assertSame('hello@example.com', $payload['contact_email'] ?? null);
        $this->assertTrue((bool) ($payload['message_correction'] ?? false));
        $this->assertStringContainsString('construction', (string) ($payload['message'] ?? ''));
    }

    public function test_semantic_false_cold_one_shot_skips_even_when_email_present(): void
    {
        [$user, $org] = $this->userWithOrg();
        $conversation = AiConversation::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'channel' => 'web',
            'status' => 'active',
            'title' => 'Cold outbound',
        ]);

        $this->mock(\App\V2\Ai\Services\SemanticTurnPlanService::class, function ($mock) {
            $mock->shouldReceive('interpret')->once()->andReturn([
                'semantic' => [
                    'cold_one_shot' => false,
                    'recipient_correction' => false,
                    'user_objective' => 'report_state',
                    'handoff_brief' => 'User is asking about an email address, not sending.',
                ],
                'enforcement' => [],
            ]);
        });

        $result = app(OneShotOutboundCommandCenterService::class)->tryHandle(
            $user,
            $org->id,
            $conversation,
            'who is hello@example.com in our CRM?',
            'web',
        );

        $this->assertNull($result);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function userMimicCases(): array
    {
        return [
            [
                'message' => 'email this to sam@northwind.io',
                'channel' => 'email',
                'email' => 'sam@northwind.io',
            ],
            [
                'message' => 'get a planed reply for sam@northwind.io and email him',
                'channel' => 'email',
                'email' => 'sam@northwind.io',
            ],
            [
                'message' => 'check https://northwind.io/pricing and email sam@northwind.io',
                'channel' => 'email',
                'email' => 'sam@northwind.io',
                'research_url' => 'https://northwind.io/pricing',
            ],
            [
                'message' => 'pls send one mail to finance@contoso.test',
                'channel' => 'email',
                'email' => 'finance@contoso.test',
            ],
            [
                'message' => 'DM this LinkedIn https://www.linkedin.com/in/sam-lee-99',
                'channel' => 'linkedin',
                'linkedin_url' => 'https://www.linkedin.com/in/sam-lee-99',
            ],
            [
                'message' => 'message this person once https://linkedin.com/in/ada-founder',
                'channel' => 'linkedin',
            ],
            [
                'message' => 'send an Instagram DM to https://instagram.com/ops.lead',
                'channel' => 'instagram',
            ],
            [
                'message' => 'ig dm https://www.instagram.com/brand.hq',
                'channel' => 'instagram',
            ],
            [
                'message' => 'WhatsApp this number +15551234567',
                'channel' => 'whatsapp',
            ],
            [
                'message' => 'wa +44 7700 900123 a short hello',
                'channel' => 'whatsapp',
            ],
            [
                'message' => 'Telegram message to https://t.me/samlee',
                'channel' => 'telegram',
            ],
            [
                'message' => 'telegram @saleslead one note',
                'channel' => 'telegram',
            ],
            [
                'message' => 'DM them on X https://x.com/samlee',
                'channel' => 'twitter',
            ],
            [
                'message' => 'tweet dm https://twitter.com/contoso_ops',
                'channel' => 'twitter',
            ],
        ];
    }

    /**
     * @param  list<string>  $facts
     */
    private function mockPassingDraftQuality(array $facts = []): void
    {
        $this->mock(OutboundDraftQualityService::class, function ($mock) use ($facts) {
            $mock->shouldReceive('researchGateAllowsDraft')->andReturn(true);
            $mock->shouldReceive('evaluate')->andReturn([
                'pass' => true,
                'score' => 0.9,
                'grounded' => true,
                'not_generic' => true,
                'has_clear_cta' => true,
                'invents_facts' => false,
                'research_claimed_but_missing' => false,
                'evidence_used' => $facts,
                'issues' => [],
                'summary' => 'Ready for Review & Launch',
                'source' => 'fallback',
            ]);
            $mock->shouldReceive('researchFactBullets')->andReturn($facts);
        });
    }

    /**
     * @return array{0: User, 1: V2Organization}
     */
    private function userWithOrg(): array
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Generic Workspace',
            'slug' => 'generic-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $user->forceFill(['current_organization_id' => $org->id])->save();

        return [$user->fresh(), $org];
    }
}

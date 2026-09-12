<?php

namespace Tests\Feature\V2\Ai;

use App\Jobs\V2\ProactiveInboundReplyJob;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use App\Models\V2Conversation;
use App\Models\V2Message;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLead;
use App\V2\Ai\Enums\AiAutonomyLevel;
use App\V2\Ai\Services\AiEmployeeSettingsService;
use App\V2\Ai\Services\InboxSociHandlingService;
use App\V2\Ai\Services\ProspectIntelligenceService;
use App\V2\Ai\Services\ProactiveInboundReplyService;
use App\V2\Services\UnifiedInboxReplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProactiveInboundReplyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('billing.require_entitlement', false);
        config()->set('socifusion_ai.proactive_inbound_reply', true);
    }

    public function test_handle_inbound_dispatches_proactive_job_after_research(): void
    {
        Bus::fake([ProactiveInboundReplyJob::class]);

        [$user, $conversation, $lead] = $this->conversationFixtures();

        V2Message::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'body' => 'Thanks — see https://example.com/product for our site.',
            'received_at' => now(),
        ]);

        app(UnifiedInboxReplyService::class)->handleInbound(
            $conversation,
            'Thanks — see https://example.com/product for our site.',
            $user->id,
        );

        Bus::assertDispatched(ProactiveInboundReplyJob::class, function (ProactiveInboundReplyJob $job) use ($conversation) {
            return $job->v2ConversationId === $conversation->id;
        });

        $lead->refresh();
        $dossier = is_array($lead->meta['prospect_dossier'] ?? null) ? $lead->meta['prospect_dossier'] : [];
        $this->assertSame('qualifying', $dossier['conversion_stage'] ?? null);
    }

    public function test_proactive_service_marks_handled_and_notifies_command_center(): void
    {
        Http::fake([
            '*' => Http::response(['choices' => [['message' => ['content' => 'Thanks for sharing — happy to tailor this for Phanrise.']]]], 200),
        ]);
        config()->set('services.openai.api_key', 'test-key');

        [$user, $conversation, $lead] = $this->conversationFixtures();

        $inbound = V2Message::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'body' => 'Tell me more about tailored solutions https://engr.phanrise.com/',
            'received_at' => now(),
        ]);

        app(ProspectIntelligenceService::class)->processInbound($lead, $conversation, $inbound->body);

        $commandCenter = AiConversation::query()->create([
            'organization_id' => $user->current_organization_id,
            'user_id' => $user->id,
            'channel' => 'command_center',
            'status' => 'open',
            'title' => 'Command Center',
        ]);

        app(ProactiveInboundReplyService::class)->handle(
            $conversation->id,
            $user->id,
            $inbound->id,
        );

        $conversation->refresh();
        $this->assertNotNull(app(InboxSociHandlingService::class)->handlingMeta($conversation));

        $this->assertTrue(
            AiMessage::query()
                ->where('conversation_id', $commandCenter->id)
                ->where('role', 'assistant')
                ->where('content', 'like', '%reply%')
                ->exists()
        );
    }

    /**
     * @return array{0: User, 1: V2Conversation, 2: V2OutreachLead}
     */
    private function conversationFixtures(): array
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Proactive Org',
            'slug' => 'proactive-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $user->forceFill(['current_organization_id' => $org->id])->save();

        app(AiEmployeeSettingsService::class)->updateForUser(
            $user,
            $org->id,
            ['autonomy_level' => AiAutonomyLevel::Assisted->value],
            allowAutonomous: false,
        );

        $campaign = V2OutreachCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'One-shot',
            'status' => 'completed',
            'node_model' => [],
            'meta' => [
                'channel_inbox' => [
                    'email' => ['ai_context' => 'Intro about us', 'auto_reply_enabled' => false, 'pause_on_reply' => true],
                ],
            ],
        ]);

        $lead = V2OutreachLead::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'full_name' => 'William Victor',
            'email' => 'vickenconcept@gmail.com',
            'status' => 'replied',
        ]);

        $conversation = V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'email',
            'provider_chat_id' => 'vickenconcept@gmail.com',
            'status' => 'active',
            'meta' => [
                'outreach_lead_id' => $lead->id,
                'outreach_campaign_id' => $campaign->id,
                'prospect_name' => 'William Victor',
            ],
        ]);

        return [$user, $conversation, $lead];
    }
}

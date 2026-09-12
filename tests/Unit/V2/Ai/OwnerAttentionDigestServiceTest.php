<?php

namespace Tests\Unit\V2\Ai;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use App\Models\V2Conversation;
use App\Models\V2Message;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLead;
use App\V2\Ai\Services\OwnerAttentionDigestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwnerAttentionDigestServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('socifusion_ai.attention_digest.enabled', true);
        config()->set('socifusion_ai.attention_digest.interval_minutes', 30);
    }

    public function test_posts_digest_into_command_center_chat(): void
    {
        [$user, $org, $v2Conversation] = $this->fixtures();

        V2Message::query()->create([
            'conversation_id' => $v2Conversation->id,
            'direction' => 'inbound',
            'body' => 'Thank you — I want something tailored https://engr.phanrise.com/',
            'received_at' => now(),
        ]);

        $aiConversation = AiConversation::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'channel' => 'command_center',
            'status' => 'open',
            'title' => 'Command Center',
        ]);

        $posted = app(OwnerAttentionDigestService::class)->maybePost($user, $org->id, 'command_center_open');

        $this->assertTrue($posted);
        $this->assertTrue(
            AiMessage::query()
                ->where('conversation_id', $aiConversation->id)
                ->where('role', 'assistant')
                ->where('content', 'like', '%attention digest%')
                ->exists()
        );
        $this->assertStringContainsString('vickenconcept@gmail.com', (string) AiMessage::query()->latest('id')->value('content'));
    }

    public function test_skips_duplicate_digest_until_content_changes(): void
    {
        [$user, $org, $v2Conversation] = $this->fixtures();

        V2Message::query()->create([
            'conversation_id' => $v2Conversation->id,
            'direction' => 'inbound',
            'body' => 'Still waiting for your reply',
            'received_at' => now(),
        ]);

        AiConversation::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'channel' => 'command_center',
            'status' => 'open',
            'title' => 'Command Center',
        ]);

        $service = app(OwnerAttentionDigestService::class);
        $this->assertTrue($service->maybePost($user, $org->id, 'scheduled'));
        $this->assertFalse($service->maybePost($user, $org->id, 'scheduled'));
    }

    public function test_skips_when_nothing_needs_attention(): void
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Quiet Org',
            'slug' => 'quiet-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $user->forceFill(['current_organization_id' => $org->id])->save();

        AiConversation::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'channel' => 'command_center',
            'status' => 'open',
        ]);

        $this->assertFalse(app(OwnerAttentionDigestService::class)->maybePost($user, $org->id, 'scheduled'));
    }

    public function test_digest_lists_top_items_and_others_count(): void
    {
        [$user, $org, $emailConversation] = $this->fixtures();

        $campaignId = (int) V2OutreachCampaign::query()->where('user_id', $user->id)->value('id');

        for ($i = 0; $i < 3; $i++) {
            $conversation = V2Conversation::query()->create([
                'user_id' => $user->id,
                'provider' => 'linkedin',
                'provider_chat_id' => 'linkedin-extra-'.$i,
                'status' => 'active',
                'last_message_at' => now()->subMinutes($i + 1),
                'meta' => [
                    'source' => 'unified_inbox',
                    'outreach_campaign_id' => $campaignId,
                    'prospect_name' => 'LinkedIn Lead '.$i,
                ],
            ]);

            V2Message::query()->create([
                'conversation_id' => $conversation->id,
                'direction' => 'inbound',
                'body' => 'Hello from lead '.$i,
                'received_at' => now()->subMinutes($i + 1),
            ]);
        }

        V2Message::query()->create([
            'conversation_id' => $emailConversation->id,
            'direction' => 'inbound',
            'body' => 'Hot email please tailor https://engr.phanrise.com/',
            'received_at' => now(),
        ]);

        $service = app(OwnerAttentionDigestService::class);
        $content = $service->buildDigestMessage($user, $org->id);

        $this->assertNotNull($content);
        $this->assertStringContainsString('other thread(s) waiting', $content);
        $this->assertLessThanOrEqual(2, substr_count($content, '[Open inbox]('));

        $snap = app(\App\V2\Ai\Services\CommandCenterAwarenessService::class)->snapshot($user, $org->id);
        $whatsapp = $service->buildWhatsAppDigest($snap, \App\V2\Ai\Enums\AiAutonomyLevel::Autopilot, null, $user);
        $this->assertStringContainsString('+', $whatsapp);
        $this->assertStringNotContainsString('app.socifusion.com', $whatsapp);
    }

    /**
     * @return array{0: User, 1: V2Organization, 2: V2Conversation}
     */
    private function fixtures(): array
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Digest Org',
            'slug' => 'digest-'.uniqid(),
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
            'name' => 'Email to vickenconcept',
            'status' => 'completed',
            'node_model' => [],
        ]);

        $lead = V2OutreachLead::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'full_name' => 'vickenconcept',
            'email' => 'vickenconcept@gmail.com',
            'status' => 'replied',
        ]);

        $conversation = V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'email',
            'provider_chat_id' => 'vickenconcept@gmail.com',
            'status' => 'active',
            'last_read_at' => now(),
            'meta' => [
                'source' => 'unified_inbox',
                'outreach_campaign_id' => $campaign->id,
                'outreach_lead_id' => $lead->id,
                'prospect_name' => 'vickenconcept',
            ],
        ]);

        return [$user, $org, $conversation];
    }
}

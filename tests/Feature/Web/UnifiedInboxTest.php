<?php

namespace Tests\Feature\Web;

use App\Jobs\V2\ProcessUnipileWebhookEventJob;
use App\Models\User;
use App\Models\V2Conversation;
use App\Models\V2IntegrationAccount;
use App\Models\V2Message;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLead;
use App\Models\V2OutreachLeadProgress;
use App\Models\V2ProviderEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnifiedInboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('billing.require_entitlement', false);
    }

    public function test_inbox_index_lists_platforms(): void
    {
        $user = $this->userWithOrg();

        V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'whatsapp',
            'provider_chat_id' => 'wa_chat_1',
            'status' => 'active',
            'meta' => [
                'source' => 'unified_inbox',
                'outreach_campaign_id' => 1,
            ],
        ]);

        V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_chat_id' => 'li_chat_1',
            'status' => 'active',
            'meta' => ['source' => 'call_manager'],
        ]);

        $response = $this->actingAs($user)->get(route('inbox'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('crm/inbox/Index')
            ->has('platforms', 6)
        );
    }

    public function test_platform_inbox_includes_linkedin(): void
    {
        $user = $this->userWithOrg();

        V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_chat_id' => 'li_1',
            'status' => 'active',
            'meta' => [
                'source' => 'unified_inbox',
                'outreach_campaign_id' => 99,
                'outreach_lead_id' => 1,
            ],
        ]);

        V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_chat_id' => 'li_call_mgr',
            'status' => 'active',
            'meta' => ['source' => 'call_manager'],
        ]);

        $response = $this->actingAs($user)->get(route('inbox.platform', 'linkedin'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('crm/inbox/Platform')
            ->where('platform', 'linkedin')
            ->has('conversations', 1)
        );
    }

    public function test_platform_inbox_filters_by_channel(): void
    {
        $user = $this->userWithOrg();

        V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'whatsapp',
            'provider_chat_id' => 'wa_1',
            'status' => 'active',
            'meta' => ['source' => 'unified_inbox', 'outreach_campaign_id' => 1],
        ]);

        V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'instagram',
            'provider_chat_id' => 'ig_1',
            'status' => 'active',
            'meta' => ['source' => 'unified_inbox', 'outreach_campaign_id' => 1],
        ]);

        $response = $this->actingAs($user)->get(route('inbox.platform', 'whatsapp'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('crm/inbox/Platform')
            ->where('platform', 'whatsapp')
            ->has('conversations', 1)
        );
    }

    public function test_whatsapp_webhook_creates_unified_inbox_conversation(): void
    {
        $user = $this->userWithOrg();

        V2IntegrationAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'whatsapp',
            'provider_account_id' => 'wa_acc_1',
            'status' => 'active',
            'meta' => ['unipile_account_id' => 'wa_acc_1'],
        ]);

        $campaign = V2OutreachCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'name' => 'WA Campaign',
            'status' => 'active',
            'node_model' => [],
        ]);

        V2OutreachLead::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'full_name' => 'Jane WhatsApp',
            'phone' => '+15551234567',
            'meta' => ['whatsapp_provider_id' => 'wa_user_99'],
            'status' => 'active',
        ]);

        $event = V2ProviderEvent::query()->create([
            'user_id' => $user->id,
            'provider' => 'unipile',
            'event_id' => 'evt_wa_inbound_1',
            'event_type' => 'message.received',
            'payload' => [
                'provider' => 'WHATSAPP',
                'account_id' => 'wa_acc_1',
                'chat_id' => 'wa_chat_inbound_1',
                'data' => [
                    'chat_id' => 'wa_chat_inbound_1',
                    'message_id' => 'msg_wa_1',
                    'text' => 'Hi, interested!',
                    'sender' => [
                        'provider_id' => 'wa_user_99',
                        'display_name' => 'Jane WhatsApp',
                    ],
                ],
            ],
        ]);

        ProcessUnipileWebhookEventJob::dispatchSync($event->id);

        $conversation = V2Conversation::query()
            ->where('user_id', $user->id)
            ->where('provider', 'whatsapp')
            ->where('provider_chat_id', 'wa_chat_inbound_1')
            ->first();

        $this->assertNotNull($conversation);
        $this->assertSame('unified_inbox', $conversation->meta['source'] ?? null);
        $this->assertSame($campaign->id, (int) ($conversation->meta['outreach_campaign_id'] ?? 0));
        $this->assertDatabaseHas('v2_messages', [
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'body' => 'Hi, interested!',
        ]);
    }

    public function test_inbound_reply_pauses_linked_outreach_lead(): void
    {
        $user = $this->userWithOrg();

        V2IntegrationAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'whatsapp',
            'provider_account_id' => 'wa_acc_pause',
            'status' => 'active',
            'meta' => ['unipile_account_id' => 'wa_acc_pause'],
        ]);

        $campaign = V2OutreachCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'name' => 'Pause Test',
            'status' => 'running',
            'node_model' => [
                ['key' => 1, 'type' => 'action', 'channel' => 'whatsapp', 'action' => 'send_message', 'label' => 'WA'],
            ],
            'meta' => [
                'channel_inbox' => [
                    'whatsapp' => ['ai_context' => '', 'auto_reply_enabled' => false, 'pause_on_reply' => true],
                ],
            ],
        ]);

        $lead = V2OutreachLead::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'full_name' => 'Jane Pause',
            'phone' => '+15559876543',
            'meta' => ['whatsapp_provider_id' => 'wa_pause_user'],
            'status' => 'running',
        ]);

        V2OutreachLeadProgress::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'outreach_lead_id' => $lead->id,
            'current_node_key' => 2,
            'next_node_key' => 3,
            'run_status' => 1,
            'channel_state' => [],
        ]);

        $event = V2ProviderEvent::query()->create([
            'user_id' => $user->id,
            'provider' => 'unipile',
            'event_id' => 'evt_wa_pause_1',
            'event_type' => 'message.received',
            'payload' => [
                'provider' => 'WHATSAPP',
                'account_id' => 'wa_acc_pause',
                'chat_id' => 'wa_chat_pause_1',
                'data' => [
                    'chat_id' => 'wa_chat_pause_1',
                    'message_id' => 'msg_pause_1',
                    'text' => 'Yes, tell me more',
                    'sender' => [
                        'provider_id' => 'wa_pause_user',
                        'display_name' => 'Jane Pause',
                    ],
                ],
            ],
        ]);

        ProcessUnipileWebhookEventJob::dispatchSync($event->id);

        $lead->refresh();
        $progress = V2OutreachLeadProgress::query()
            ->where('outreach_lead_id', $lead->id)
            ->first();

        $this->assertSame('replied', $lead->status);
        $this->assertTrue($progress->acceptance_status);
        $this->assertNull($progress->next_run_at);
        $this->assertTrue($progress->channel_state['whatsapp']['replied'] ?? false);
    }

    public function test_call_manager_linkedin_never_appears_in_outreach_inbox(): void
    {
        $user = $this->userWithOrg();

        V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_chat_id' => 'li_cm_only',
            'status' => 'active',
            'meta' => ['source' => 'call_manager', 'prospect_name' => 'Call Manager Lead'],
        ]);

        $response = $this->actingAs($user)->get(route('inbox.platform', 'linkedin'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('conversations', 0)
        );
    }

    public function test_campaign_channel_inbox_settings_can_be_saved(): void
    {
        $user = $this->userWithOrg();

        $campaign = V2OutreachCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'name' => 'Settings Test',
            'status' => 'draft',
            'node_model' => [
                ['key' => 1, 'type' => 'action', 'channel' => 'whatsapp', 'action' => 'send_message', 'label' => 'WA'],
            ],
        ]);

        $response = $this->actingAs($user)->put(route('outreach.channel-inbox', [$campaign->id, 'whatsapp']), [
            'ai_context' => 'We sell CRM software to agencies on WhatsApp.',
            'auto_reply_enabled' => true,
            'pause_on_reply' => true,
        ]);

        $response->assertRedirect();
        $campaign->refresh();
        $this->assertSame(
            'We sell CRM software to agencies on WhatsApp.',
            $campaign->meta['channel_inbox']['whatsapp']['ai_context'] ?? null
        );
        $this->assertTrue($campaign->meta['channel_inbox']['whatsapp']['auto_reply_enabled'] ?? false);
    }

    public function test_inbox_send_queues_outbound_message(): void
    {
        $user = $this->userWithOrg();

        V2IntegrationAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'whatsapp',
            'provider_account_id' => 'wa_acc_2',
            'status' => 'active',
            'meta' => ['unipile_account_id' => 'wa_acc_2'],
        ]);

        $conversation = V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'whatsapp',
            'provider_chat_id' => 'wa_chat_reply',
            'status' => 'active',
            'meta' => [
                'source' => 'unified_inbox',
                'outreach_campaign_id' => 1,
                'prospect_name' => 'Jane',
            ],
        ]);

        config()->set('services.unipile.mock', true);

        $response = $this->actingAs($user)
            ->from(route('inbox.show', ['whatsapp', $conversation->id]))
            ->post(route('inbox.send', ['whatsapp', $conversation->id]), [
                'body' => 'Thanks for reaching out!',
            ]);

        $response->assertRedirect(route('inbox.show', ['whatsapp', $conversation->id]));
        $this->assertDatabaseHas('v2_messages', [
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'body' => 'Thanks for reaching out!',
        ]);
    }

    public function test_opening_thread_syncs_inbound_messages_from_unipile(): void
    {
        $user = $this->userWithOrg();

        V2IntegrationAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'whatsapp',
            'provider_account_id' => 'wa_sync_acc',
            'status' => 'active',
            'meta' => ['unipile_account_id' => 'wa_sync_acc'],
        ]);

        $conversation = V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'whatsapp',
            'provider_chat_id' => 'wa_sync_chat',
            'status' => 'active',
            'meta' => [
                'source' => 'unified_inbox',
                'outreach_campaign_id' => 1,
            ],
        ]);

        $mock = $this->mock(\App\V2\Integrations\Unipile\UnipileProvider::class);
        $mock->shouldReceive('listMessages')
            ->once()
            ->with('wa_sync_chat', ['limit' => 50], ['account_id' => 'wa_sync_acc'])
            ->andReturn([
                'items' => [
                    [
                        'id' => 'msg_in_1',
                        'text' => 'Who are you?',
                        'is_sender' => 0,
                        'timestamp' => now()->toIso8601String(),
                    ],
                ],
            ]);

        $response = $this->actingAs($user)->get(route('inbox.show', ['whatsapp', $conversation->id]));

        $response->assertOk();
        $this->assertDatabaseHas('v2_messages', [
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'body' => 'Who are you?',
            'provider_message_id' => 'msg_in_1',
        ]);
    }

    public function test_opening_linkedin_thread_syncs_inbound_messages_from_unipile(): void
    {
        $user = $this->userWithOrg();

        V2IntegrationAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_account_id' => 'li_sync_acc',
            'status' => 'active',
            'meta' => ['unipile_account_id' => 'li_sync_acc'],
        ]);

        $conversation = V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_chat_id' => 'li_sync_chat',
            'status' => 'active',
            'meta' => [
                'source' => 'unified_inbox',
                'outreach_campaign_id' => 1,
            ],
        ]);

        $mock = $this->mock(\App\V2\Integrations\Unipile\UnipileProvider::class);
        $mock->shouldReceive('listMessages')
            ->once()
            ->with('li_sync_chat', ['limit' => 50], ['account_id' => 'li_sync_acc'])
            ->andReturn([
                'items' => [
                    [
                        'id' => 'msg_li_in_1',
                        'text' => 'Thanks for connecting!',
                        'is_sender' => 0,
                        'timestamp' => now()->toIso8601String(),
                    ],
                ],
            ]);

        $response = $this->actingAs($user)->get(route('inbox.show', ['linkedin', $conversation->id]));

        $response->assertOk();
        $this->assertDatabaseHas('v2_messages', [
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'body' => 'Thanks for connecting!',
        ]);
    }

    public function test_all_inbox_platforms_share_ai_and_sync_pipeline(): void
    {
        $expected = ['linkedin', 'whatsapp', 'instagram', 'telegram', 'twitter', 'email'];

        $this->assertSame($expected, \App\V2\Services\OutreachChannelInboxSettingsService::validInboxChannels());
        $this->assertSame($expected, \App\V2\Services\UnifiedInboxReplyService::INBOX_CHANNELS);
    }

    public function test_instagram_inbound_pauses_only_that_lead(): void
    {
        $user = $this->userWithOrg();

        V2IntegrationAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'instagram',
            'provider_account_id' => 'ig_acc_pause',
            'status' => 'active',
            'meta' => ['unipile_account_id' => 'ig_acc_pause'],
        ]);

        $campaign = V2OutreachCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'name' => 'IG Pause Test',
            'status' => 'running',
            'node_model' => [
                ['key' => 1, 'type' => 'action', 'channel' => 'instagram', 'action' => 'send_message', 'label' => 'IG'],
            ],
            'meta' => [
                'channel_inbox' => [
                    'instagram' => ['ai_context' => 'We sell courses.', 'auto_reply_enabled' => false, 'pause_on_reply' => true],
                ],
            ],
        ]);

        $lead = V2OutreachLead::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'full_name' => 'Jane IG',
            'meta' => ['instagram_provider_id' => 'ig_pause_user'],
            'status' => 'running',
        ]);

        $otherLead = V2OutreachLead::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'full_name' => 'Other Lead',
            'meta' => ['instagram_provider_id' => 'ig_other_user'],
            'status' => 'running',
        ]);

        V2OutreachLeadProgress::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'outreach_lead_id' => $lead->id,
            'current_node_key' => 1,
            'next_node_key' => 2,
            'run_status' => 1,
            'channel_state' => [],
        ]);

        V2OutreachLeadProgress::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'outreach_lead_id' => $otherLead->id,
            'current_node_key' => 1,
            'next_node_key' => 2,
            'run_status' => 1,
            'channel_state' => [],
        ]);

        $event = V2ProviderEvent::query()->create([
            'user_id' => $user->id,
            'provider' => 'unipile',
            'event_id' => 'evt_ig_pause_1',
            'event_type' => 'message.received',
            'payload' => [
                'provider' => 'INSTAGRAM',
                'account_id' => 'ig_acc_pause',
                'chat_id' => 'ig_chat_pause_1',
                'data' => [
                    'chat_id' => 'ig_chat_pause_1',
                    'message_id' => 'msg_ig_pause_1',
                    'text' => 'Interested!',
                    'sender' => [
                        'provider_id' => 'ig_pause_user',
                        'display_name' => 'Jane IG',
                    ],
                ],
            ],
        ]);

        ProcessUnipileWebhookEventJob::dispatchSync($event->id);

        $lead->refresh();
        $otherLead->refresh();

        $this->assertSame('replied', $lead->status);
        $this->assertSame('running', $otherLead->status);
    }

    public function test_inbox_poll_syncs_and_returns_messages_json(): void
    {
        $user = $this->userWithOrg();

        V2IntegrationAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'whatsapp',
            'provider_account_id' => 'wa_poll_acc',
            'status' => 'active',
            'meta' => ['unipile_account_id' => 'wa_poll_acc'],
        ]);

        $conversation = V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'whatsapp',
            'provider_chat_id' => 'wa_poll_chat',
            'status' => 'active',
            'meta' => [
                'source' => 'unified_inbox',
                'outreach_campaign_id' => 1,
            ],
        ]);

        V2Message::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'body' => 'Hello there',
            'sent_at' => now(),
        ]);

        $mock = $this->mock(\App\V2\Integrations\Unipile\UnipileProvider::class);
        $mock->shouldReceive('listMessages')
            ->once()
            ->andReturn([
                'items' => [
                    [
                        'id' => 'msg_poll_in',
                        'text' => 'New reply!',
                        'is_sender' => 0,
                        'timestamp' => now()->toIso8601String(),
                    ],
                ],
            ]);

        $response = $this->actingAs($user)->getJson(route('inbox.poll', ['whatsapp', $conversation->id]));

        $response->assertOk();
        $response->assertJsonPath('messages.0.body', 'Hello there');
        $response->assertJsonFragment(['body' => 'New reply!', 'direction' => 'inbound']);
    }

    private function userWithOrg(): User
    {
        $user = User::factory()->create();
        $organization = V2Organization::query()->create([
            'name' => 'Inbox Org',
            'slug' => 'inbox-org-'.$user->id,
            'status' => 'active',
        ]);

        V2OrganizationUser::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'capabilities' => ['*'],
            'status' => 'active',
        ]);

        $user->forceFill(['current_organization_id' => $organization->id])->save();

        return $user->fresh();
    }
}

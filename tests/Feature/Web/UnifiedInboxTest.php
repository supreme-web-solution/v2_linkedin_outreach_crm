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
use App\V2\Outreach\OutreachChannelRegistry;
use App\V2\Services\UnifiedInboxReplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
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
            ->has('conversations.data', 1)
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
            ->has('conversations.data', 1)
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
        $this->assertNotTrue($progress->acceptance_status);
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
            ->has('conversations.data', 0)
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
        $expected = OutreachChannelRegistry::inboxPlatforms();

        $this->assertSame($expected, \App\V2\Services\OutreachChannelInboxSettingsService::validInboxChannels());
        $this->assertSame($expected, UnifiedInboxReplyService::inboxChannels());
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

    public function test_instagram_inbound_repairs_stale_chat_id_and_merges_orphan_thread(): void
    {
        $user = $this->userWithOrg();

        V2IntegrationAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'instagram',
            'provider_account_id' => 'ig_acc_repair',
            'status' => 'active',
            'meta' => ['unipile_account_id' => 'ig_acc_repair'],
        ]);

        $campaign = V2OutreachCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'name' => 'IG Repair Test',
            'status' => 'running',
            'node_model' => [],
        ]);

        $lead = V2OutreachLead::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'full_name' => 'Elyy',
            'meta' => ['instagram_provider_id' => '58507167660'],
            'status' => 'running',
        ]);

        $outreachThread = V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'instagram',
            'provider_chat_id' => 'WFm4Wm3DWP-wrong-message-id',
            'status' => 'active',
            'meta' => [
                'source' => 'unified_inbox',
                'outreach_lead_id' => $lead->id,
                'outreach_campaign_id' => $campaign->id,
                'attendee_ids' => ['58507167660'],
            ],
        ]);

        $orphanThread = V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'instagram',
            'provider_chat_id' => 'mL8AUZfhUqSAPVay8-oANA',
            'status' => 'active',
            'meta' => [
                'source' => 'unified_inbox',
                'attendee_ids' => ['17849467217951661'],
                'channel_label' => 'Instagram',
            ],
        ]);

        V2Message::query()->create([
            'conversation_id' => $outreachThread->id,
            'direction' => 'outbound',
            'body' => 'hello, can you see this message',
            'sent_at' => now(),
        ]);

        $event = V2ProviderEvent::query()->create([
            'user_id' => $user->id,
            'provider' => 'unipile',
            'event_id' => 'evt_ig_repair_1',
            'event_type' => 'message.received',
            'payload' => [
                'account_type' => 'INSTAGRAM',
                'account_id' => 'ig_acc_repair',
                'chat_id' => 'mL8AUZfhUqSAPVay8-oANA',
                'is_sender' => false,
                'message_id' => '4b5AQAVZUwSOunSECh3azA',
                'text' => 'How can i help you?',
                'attendees' => [[
                    'attendee_provider_id' => '17849467217951661',
                ]],
                'data' => [
                    'chat_id' => 'mL8AUZfhUqSAPVay8-oANA',
                    'message_id' => '4b5AQAVZUwSOunSECh3azA',
                    'text' => 'How can i help you?',
                ],
            ],
        ]);

        ProcessUnipileWebhookEventJob::dispatchSync($event->id);

        $this->assertDatabaseMissing('v2_conversations', ['id' => $orphanThread->id]);

        $outreachThread->refresh();
        $this->assertSame('mL8AUZfhUqSAPVay8-oANA', $outreachThread->provider_chat_id);
        $this->assertDatabaseHas('v2_messages', [
            'conversation_id' => $outreachThread->id,
            'direction' => 'inbound',
            'body' => 'How can i help you?',
        ]);
        $this->assertContains(
            '17849467217951661',
            (array) Arr::get($lead->fresh()->meta, 'instagram_attendee_ids', [])
        );
    }

    public function test_inbox_dedupes_duplicate_outreach_messages_and_orders_by_delivery_time(): void
    {
        $user = $this->userWithOrg();

        $conversation = V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'instagram',
            'provider_chat_id' => 'ig_order_chat',
            'status' => 'active',
            'meta' => [
                'source' => 'unified_inbox',
                'outreach_campaign_id' => 1,
            ],
        ]);

        V2Message::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'body' => 'hello, can you see this message',
            'sent_at' => now()->setTime(17, 48, 0),
            'created_at' => now()->setTime(17, 48, 0),
        ]);

        V2Message::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'body' => 'hello, can you see this message',
            'sent_at' => now()->setTime(17, 48, 5),
            'created_at' => now()->setTime(17, 48, 5),
        ]);

        V2Message::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'body' => 'Reply me abeg',
            'sent_at' => now()->setTime(17, 49, 0),
            'created_at' => now()->setTime(18, 20, 0),
        ]);

        V2Message::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'body' => 'Yes thank you',
            'received_at' => now()->setTime(17, 57, 0),
            'created_at' => now()->setTime(18, 19, 0),
        ]);

        V2Message::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'body' => 'How can i help you?',
            'received_at' => now()->setTime(17, 58, 0),
            'created_at' => now()->setTime(18, 18, 0),
        ]);

        app(\App\V2\Services\UnifiedInboxService::class)->dedupeConversationMessages($conversation);

        $ordered = V2Message::query()
            ->where('conversation_id', $conversation->id)
            ->orderByRaw('COALESCE(received_at, sent_at, created_at) ASC')
            ->orderBy('id')
            ->pluck('body')
            ->all();

        $this->assertSame(4, V2Message::query()->where('conversation_id', $conversation->id)->count());
        $this->assertSame([
            'hello, can you see this message',
            'Reply me abeg',
            'Yes thank you',
            'How can i help you?',
        ], $ordered);
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

    public function test_reaction_ghost_messages_are_merged_and_hidden(): void
    {
        $user = $this->userWithOrg();

        V2IntegrationAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'whatsapp',
            'provider_account_id' => 'wa_reaction_acc',
            'status' => 'active',
            'meta' => ['unipile_account_id' => 'wa_reaction_acc'],
        ]);

        $conversation = V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'whatsapp',
            'provider_chat_id' => 'wa_reaction_chat',
            'status' => 'active',
            'meta' => [
                'source' => 'unified_inbox',
                'outreach_campaign_id' => 1,
            ],
        ]);

        $target = V2Message::query()->create([
            'conversation_id' => $conversation->id,
            'provider_message_id' => 'msg_target_1',
            'direction' => 'outbound',
            'body' => 'alrigtht',
            'sent_at' => now()->subMinute(),
            'meta' => ['reactions' => [['value' => '🙏', 'sender_id' => 'u1', 'is_sender' => true]]],
        ]);

        $wrongMessage = V2Message::query()->create([
            'conversation_id' => $conversation->id,
            'provider_message_id' => 'msg_wrong_1',
            'direction' => 'outbound',
            'body' => 'Hello',
            'sent_at' => now(),
            'meta' => ['reactions' => [['value' => '🙏', 'sender_id' => 'u1', 'is_sender' => true]]],
        ]);

        $ghost = V2Message::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'body' => '{{2349036802727@s.whatsapp.net}} reacted 🙏',
            'sent_at' => now(),
        ]);

        $this->mock(\App\V2\Integrations\Unipile\UnipileProvider::class)
            ->shouldReceive('listMessages')
            ->once()
            ->andReturn([
                'items' => [
                    [
                        'id' => 'msg_target_1',
                        'text' => 'alrigtht',
                        'is_sender' => 1,
                        'timestamp' => now()->subMinute()->toIso8601String(),
                        'reactions' => [
                            ['value' => '🙏', 'sender_id' => 'u1', 'is_sender' => true],
                        ],
                    ],
                    [
                        'id' => 'msg_wrong_1',
                        'text' => 'Hello',
                        'is_sender' => 1,
                        'timestamp' => now()->toIso8601String(),
                        'reactions' => [],
                    ],
                    [
                        'id' => 'ghost_1',
                        'text' => '{{2349036802727@s.whatsapp.net}} reacted 🙏',
                        'hidden' => 1,
                        'is_event' => 1,
                        'event_type' => 2,
                        'timestamp' => now()->toIso8601String(),
                        'reactions' => [],
                    ],
                ],
            ]);

        $response = $this->actingAs($user)->get(route('inbox.show', ['whatsapp', $conversation->id]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('messages', 2)
            ->where('messages.0.body', 'alrigtht')
            ->where('messages.0.reactions.0.value', '🙏')
            ->where('messages.1.body', 'Hello')
            ->where('messages.1.reactions', [])
        );

        $this->assertDatabaseMissing('v2_messages', ['id' => $ghost->id]);
        $wrongMessage->refresh();
        $this->assertNull($wrongMessage->meta['reactions'] ?? null);
    }

    public function test_whatsapp_webhook_without_outreach_lead_does_not_create_inbox_thread(): void
    {
        $user = $this->userWithOrg();

        V2IntegrationAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'whatsapp',
            'provider_account_id' => 'wa_random_acc',
            'status' => 'active',
            'meta' => ['unipile_account_id' => 'wa_random_acc'],
        ]);

        $event = V2ProviderEvent::query()->create([
            'user_id' => $user->id,
            'provider' => 'unipile',
            'event_id' => 'evt_wa_random_1',
            'event_type' => 'message.received',
            'payload' => [
                'provider' => 'WHATSAPP',
                'account_id' => 'wa_random_acc',
                'chat_id' => 'wa_random_chat',
                'data' => [
                    'chat_id' => 'wa_random_chat',
                    'message_id' => 'msg_random_1',
                    'text' => 'Hey from a friend',
                    'sender' => [
                        'provider_id' => 'wa_unknown_user',
                        'display_name' => 'Random Friend',
                    ],
                ],
            ],
        ]);

        ProcessUnipileWebhookEventJob::dispatchSync($event->id);

        $this->assertDatabaseMissing('v2_conversations', [
            'user_id' => $user->id,
            'provider' => 'whatsapp',
            'provider_chat_id' => 'wa_random_chat',
        ]);
    }

    public function test_whatsapp_group_webhook_does_not_leak_into_one_to_one_outreach_thread(): void
    {
        $user = $this->userWithOrg();

        V2IntegrationAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'whatsapp',
            'provider_account_id' => 'wa_group_acc',
            'status' => 'active',
            'meta' => ['unipile_account_id' => 'wa_group_acc'],
        ]);

        $campaign = V2OutreachCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'name' => 'WA Group Guard',
            'status' => 'active',
            'node_model' => [],
        ]);

        $lead = V2OutreachLead::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'full_name' => 'William Victor',
            'phone' => '+2349036802727',
            'meta' => ['whatsapp_provider_id' => '2349036802727@s.whatsapp.net'],
            'status' => 'active',
        ]);

        $conversation = V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'whatsapp',
            'provider_chat_id' => null,
            'status' => 'active',
            'meta' => [
                'source' => 'unified_inbox',
                'outreach_lead_id' => $lead->id,
                'outreach_campaign_id' => $campaign->id,
                'prospect_name' => 'William Victor',
                'attendee_ids' => ['2349036802727@s.whatsapp.net'],
            ],
        ]);

        $event = V2ProviderEvent::query()->create([
            'user_id' => $user->id,
            'provider' => 'unipile',
            'event_id' => 'evt_wa_group_leak_1',
            'event_type' => 'message.received',
            'payload' => [
                'provider' => 'WHATSAPP',
                'account_id' => 'wa_group_acc',
                'chat_id' => '120363123456789012@g.us',
                'data' => [
                    'chat_id' => '120363123456789012@g.us',
                    'is_group' => true,
                    'type' => 'group',
                    'message_id' => 'msg_group_1',
                    'text' => 'Clean one bedroom flat at Ring road Awka',
                    'sender' => [
                        'provider_id' => '2348012345678@s.whatsapp.net',
                        'display_name' => 'Group Member',
                    ],
                    'attendees' => [
                        ['provider_id' => '2348012345678@s.whatsapp.net'],
                        ['provider_id' => '2349036802727@s.whatsapp.net'],
                        ['provider_id' => '2348099999999@s.whatsapp.net'],
                    ],
                ],
            ],
        ]);

        ProcessUnipileWebhookEventJob::dispatchSync($event->id);

        $conversation->refresh();

        $this->assertNull($conversation->provider_chat_id);
        $this->assertDatabaseMissing('v2_messages', [
            'conversation_id' => $conversation->id,
            'body' => 'Clean one bedroom flat at Ring road Awka',
        ]);
        $this->assertDatabaseMissing('v2_conversations', [
            'user_id' => $user->id,
            'provider' => 'whatsapp',
            'provider_chat_id' => '120363123456789012@g.us',
        ]);
    }

    public function test_sync_clears_group_chat_id_from_direct_outreach_thread(): void
    {
        $user = $this->userWithOrg();

        V2IntegrationAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'whatsapp',
            'provider_account_id' => 'wa_sync_guard_acc',
            'status' => 'active',
            'meta' => ['unipile_account_id' => 'wa_sync_guard_acc'],
        ]);

        $conversation = V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'whatsapp',
            'provider_chat_id' => '120363123456789012@g.us',
            'status' => 'active',
            'meta' => [
                'source' => 'unified_inbox',
                'outreach_campaign_id' => 1,
                'outreach_lead_id' => 1,
                'prospect_name' => 'William Victor',
                'attendee_ids' => ['2349036802727@s.whatsapp.net'],
            ],
        ]);

        $this->mock(\App\V2\Integrations\Unipile\UnipileProvider::class)
            ->shouldReceive('listMessages')
            ->never();

        app(\App\V2\Services\UnifiedInboxService::class)->syncMessagesFromProvider($conversation);

        $conversation->refresh();

        $this->assertNull($conversation->provider_chat_id);
        $this->assertSame('120363123456789012@g.us', $conversation->meta['invalid_provider_chat_id'] ?? null);
    }

    public function test_telegram_group_webhook_does_not_leak_into_one_to_one_outreach_thread(): void
    {
        $user = $this->userWithOrg();

        V2IntegrationAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'telegram',
            'provider_account_id' => 'tg_group_acc',
            'status' => 'active',
            'meta' => ['unipile_account_id' => 'tg_group_acc'],
        ]);

        $campaign = V2OutreachCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'name' => 'TG Group Guard',
            'status' => 'active',
            'node_model' => [],
        ]);

        $lead = V2OutreachLead::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'full_name' => 'Telegram Lead',
            'meta' => ['telegram_provider_id' => 'tg_lead_99'],
            'status' => 'active',
        ]);

        $conversation = V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'telegram',
            'provider_chat_id' => null,
            'status' => 'active',
            'meta' => [
                'source' => 'unified_inbox',
                'outreach_lead_id' => $lead->id,
                'outreach_campaign_id' => $campaign->id,
                'prospect_name' => 'Telegram Lead',
                'attendee_ids' => ['tg_lead_99'],
            ],
        ]);

        $event = V2ProviderEvent::query()->create([
            'user_id' => $user->id,
            'provider' => 'unipile',
            'event_id' => 'evt_tg_group_leak_1',
            'event_type' => 'message.received',
            'payload' => [
                'provider' => 'TELEGRAM',
                'account_id' => 'tg_group_acc',
                'chat_id' => '-1001234567890',
                'data' => [
                    'chat_id' => '-1001234567890',
                    'type' => 'group',
                    'message_id' => 'msg_tg_group_1',
                    'text' => 'Group promo message',
                    'sender' => [
                        'provider_id' => 'tg_other_user',
                        'display_name' => 'Random Member',
                    ],
                    'attendees' => [
                        ['provider_id' => 'tg_other_user'],
                        ['provider_id' => 'tg_lead_99'],
                        ['provider_id' => 'tg_admin_user'],
                    ],
                ],
            ],
        ]);

        ProcessUnipileWebhookEventJob::dispatchSync($event->id);

        $conversation->refresh();

        $this->assertNull($conversation->provider_chat_id);
        $this->assertDatabaseMissing('v2_messages', [
            'conversation_id' => $conversation->id,
            'body' => 'Group promo message',
        ]);
    }

    public function test_mail_received_webhook_creates_email_inbox_thread(): void
    {
        $user = $this->userWithOrg();

        V2IntegrationAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'email',
            'provider_account_id' => 'email_acc_1',
            'status' => 'active',
            'meta' => ['unipile_account_id' => 'email_acc_1', 'email' => 'sender@company.com'],
        ]);

        $campaign = V2OutreachCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'name' => 'Email Campaign',
            'status' => 'running',
            'node_model' => [],
        ]);

        $lead = V2OutreachLead::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'full_name' => 'William Victor',
            'email' => 'vickenconcept@gmail.com',
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

        $event = V2ProviderEvent::query()->create([
            'user_id' => $user->id,
            'provider' => 'unipile',
            'event_id' => 'evt_mail_received_1',
            'event_type' => 'mail.received',
            'payload' => [
                'event' => 'mail_received',
                'account_id' => 'email_acc_1',
                'email_id' => 'email_msg_1',
                'from_attendee' => [
                    'identifier' => 'vickenconcept@gmail.com',
                    'display_name' => 'William Victor',
                ],
                'to_attendees' => [
                    ['identifier' => 'sender@company.com'],
                ],
                'subject' => 'Re: Unlock Your Coding Potential',
                'body' => 'Thanks, tell me more about the course.',
                'role' => 'inbox',
            ],
        ]);

        ProcessUnipileWebhookEventJob::dispatchSync($event->id);

        $this->assertDatabaseHas('v2_conversations', [
            'user_id' => $user->id,
            'provider' => 'email',
            'provider_chat_id' => 'vickenconcept@gmail.com',
        ]);

        $conversation = V2Conversation::query()
            ->where('user_id', $user->id)
            ->where('provider', 'email')
            ->where('provider_chat_id', 'vickenconcept@gmail.com')
            ->first();

        $this->assertNotNull($conversation);
        $this->assertDatabaseHas('v2_messages', [
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'body' => 'Thanks, tell me more about the course.',
        ]);
        $this->assertSame('replied', $lead->fresh()->status);
    }

    public function test_inbox_index_includes_unread_count_per_platform(): void
    {
        $user = $this->userWithOrg();

        $conversation = V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'whatsapp',
            'provider_chat_id' => 'wa_unread',
            'status' => 'active',
            'meta' => ['source' => 'unified_inbox', 'outreach_campaign_id' => 1],
        ]);

        V2Message::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'body' => 'New reply',
            'received_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('inbox'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('crm/inbox/Index')
            ->where('platforms.1.key', 'whatsapp')
            ->where('platforms.1.unread_count', 1)
        );
    }

    public function test_platform_auto_opens_first_unread_conversation(): void
    {
        $user = $this->userWithOrg();

        $readConversation = V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'whatsapp',
            'provider_chat_id' => 'wa_read',
            'status' => 'active',
            'last_message_at' => now()->subHour(),
            'meta' => [
                'source' => 'unified_inbox',
                'outreach_campaign_id' => 1,
                'last_read_at' => now()->toIso8601String(),
            ],
        ]);

        V2Message::query()->create([
            'conversation_id' => $readConversation->id,
            'direction' => 'outbound',
            'body' => 'Hello',
            'sent_at' => now()->subHours(2),
        ]);

        $unreadConversation = V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'whatsapp',
            'provider_chat_id' => 'wa_unread_open',
            'status' => 'active',
            'last_message_at' => now(),
            'meta' => ['source' => 'unified_inbox', 'outreach_campaign_id' => 1],
        ]);

        V2Message::query()->create([
            'conversation_id' => $unreadConversation->id,
            'direction' => 'inbound',
            'body' => 'Got your message',
            'received_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('inbox.platform', 'whatsapp'))
            ->assertRedirect(route('inbox.show', ['whatsapp', $unreadConversation->id]));
    }

    public function test_opening_thread_marks_conversation_as_read(): void
    {
        $user = $this->userWithOrg();

        V2IntegrationAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'whatsapp',
            'provider_account_id' => 'wa_read_acc',
            'status' => 'active',
            'meta' => ['unipile_account_id' => 'wa_read_acc'],
        ]);

        $conversation = V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'whatsapp',
            'provider_chat_id' => 'wa_read_test',
            'status' => 'active',
            'meta' => ['source' => 'unified_inbox', 'outreach_campaign_id' => 1],
        ]);

        V2Message::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'body' => 'Unread message',
            'received_at' => now(),
        ]);

        $this->mock(\App\V2\Integrations\Unipile\UnipileProvider::class)
            ->shouldReceive('listMessages')
            ->once()
            ->andReturn(['items' => []]);

        $response = $this->actingAs($user)->get(route('inbox.show', ['whatsapp', $conversation->id]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('unread_count', 0)
            ->where('conversations.data.0.is_unread', false)
        );

        $conversation->refresh();
        $this->assertNotNull(Arr::get($conversation->meta ?? [], 'last_read_at'));
    }

    public function test_inbox_conversation_and_message_can_be_deleted(): void
    {
        $user = $this->userWithOrg();

        $conversation = V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'email',
            'provider_chat_id' => 'lead@example.com',
            'status' => 'active',
            'meta' => [
                'source' => 'unified_inbox',
                'outreach_campaign_id' => 1,
                'prospect_email' => 'lead@example.com',
            ],
        ]);

        $message = V2Message::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'body' => 'Hello there',
            'received_at' => now(),
        ]);

        $this->actingAs($user)
            ->delete(route('inbox.message.destroy', ['platform' => 'email', 'id' => $conversation->id, 'messageId' => $message->id]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('v2_messages', ['id' => $message->id]);

        $this->actingAs($user)
            ->delete(route('inbox.destroy', ['platform' => 'email', 'id' => $conversation->id]))
            ->assertRedirect(route('inbox.platform', 'email'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('v2_conversations', ['id' => $conversation->id]);
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

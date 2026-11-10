<?php

namespace Tests\Unit\Integrations;

use App\V2\Integrations\Unipile\UnipileProvider;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class UnipileWebhookEventTypeTest extends TestCase
{
    private UnipileProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.unipile.base_url', 'https://unipile.test/v2');
        Config::set('services.unipile.api_key', 'test-key');
        Config::set('services.unipile.mock', false);
        $this->provider = app(UnipileProvider::class);
    }

    public function test_maps_unipile_v2_messaging_events(): void
    {
        $this->assertSame('message.received', $this->provider->eventType(['type' => 'message.new']));
        $this->assertSame('message.reaction', $this->provider->eventType(['type' => 'message.reaction.new']));
        $this->assertSame('chat.read', $this->provider->eventType(['type' => 'message.receipt.read']));
        $this->assertSame('message.delivered', $this->provider->eventType(['type' => 'message.receipt.delivery']));
        $this->assertSame('message.delivered', $this->provider->eventType(['type' => 'message.delivery']));
        $this->assertSame('message.sent', $this->provider->eventType(['type' => 'message.update']));
    }

    public function test_maps_message_new_from_owner_as_sent(): void
    {
        // v2 Message uses is_sender (documented); is_from_me is a legacy alias.
        $this->assertSame('message.sent', $this->provider->eventType([
            'type' => 'message.new',
            'payload' => ['is_sender' => true],
        ]));
        $this->assertSame('message.sent', $this->provider->eventType([
            'type' => 'message.new',
            'payload' => ['is_from_me' => true],
        ]));
    }

    public function test_maps_unipile_v2_account_and_relation_events(): void
    {
        $this->assertSame('account.disconnected', $this->provider->eventType(['type' => 'account.status.disconnected']));
        $this->assertSame('account.error', $this->provider->eventType(['type' => 'account.status.errored']));
        $this->assertSame('account.reconnected', $this->provider->eventType(['type' => 'account.reconnect']));
        $this->assertSame('account.add', $this->provider->eventType(['type' => 'account.add']));
        $this->assertSame('account.status.running', $this->provider->eventType(['type' => 'account.status.running']));
        $this->assertSame('new_relation', $this->provider->eventType(['type' => 'relation.new']));
    }

    public function test_maps_unipile_v2_email_and_tracking_events(): void
    {
        $this->assertSame('mail.received', $this->provider->eventType(['type' => 'email.new']));
        $this->assertSame('mail.received', $this->provider->eventType([
            'type' => 'email.new',
            'payload' => ['folders' => [['role' => 'INBOX']]],
        ]));
        $this->assertSame('email.sent', $this->provider->eventType([
            'type' => 'email.new',
            'payload' => ['folders' => [['role' => 'SENT']]],
        ]));
        // Live v2: folders are opaque IDs — infer SENT when From matches account_name.
        $this->assertSame('email.sent', $this->provider->eventType([
            'type' => 'email.new',
            'account_name' => 'me@example.com',
            'payload' => [
                'folder_id' => 'folder_opaque_sent_1',
                'email' => [
                    'id' => 'email_sent_1',
                    'from' => [['email' => 'me@example.com']],
                    'to' => [['email' => 'lead@example.com']],
                    'folders' => ['folder_opaque_sent_1'],
                    'body_plain' => 'Outbound copy',
                ],
            ],
        ]));
        $this->assertSame('mail.received', $this->provider->eventType([
            'type' => 'email.new',
            'account_name' => 'me@example.com',
            'payload' => [
                'folder_id' => 'folder_opaque_inbox_1',
                'email' => [
                    'id' => 'email_in_1',
                    'from' => [['email' => 'lead@example.com']],
                    'to' => [['email' => 'me@example.com']],
                    'folders' => ['folder_opaque_inbox_1'],
                    'body_plain' => 'Inbound reply',
                ],
            ],
        ]));
        $this->assertSame('email.bounced', $this->provider->eventType(['type' => 'email.new.bounce']));
        $this->assertSame('email.opened', $this->provider->eventType(['type' => 'tracking.open']));
        $this->assertSame('email.clicked', $this->provider->eventType(['type' => 'tracking.click']));
    }

    public function test_maps_unipile_v2_calendar_events(): void
    {
        $this->assertSame('calendar.event.created', $this->provider->eventType(['type' => 'calendar.event.new']));
        $this->assertSame('calendar.event.updated', $this->provider->eventType(['type' => 'calendar.event.update']));
    }

    public function test_parse_event_flattens_v2_message_new_payload(): void
    {
        // Docs: message.new payload IS the Message object (sender_id, text, is_sender, chat_id).
        $parsed = $this->provider->parseEvent([
            'id' => 'evt_msg_1',
            'type' => 'message.new',
            'account_id' => 'acc_li',
            'account_provider' => 'LINKEDIN',
            'payload' => [
                'id' => 'msg_1',
                'chat_id' => 'chat_1',
                'text' => 'Hello from prospect',
                'sender_id' => 'ACoAAProspect123',
                'is_sender' => false,
            ],
        ]);

        $this->assertSame('chat_1', $parsed['chat_id']);
        $this->assertSame('Hello from prospect', $parsed['text']);
        $this->assertSame('msg_1', $parsed['message_id']);
        $this->assertSame('chat_1', $parsed['data']['chat_id']);
        $this->assertSame('ACoAAProspect123', $parsed['data']['sender_id']);
        $this->assertFalse((bool) $parsed['data']['is_sender']);
        $this->assertSame('LINKEDIN', $parsed['account_provider']);
        $this->assertSame('LINKEDIN', $parsed['data']['account_provider']);
        $this->assertSame('LINKEDIN', $parsed['provider']);
        $this->assertSame('message.received', $this->provider->eventType([
            'type' => 'message.new',
            'payload' => ['is_sender' => false, 'sender_id' => 'ACoAAProspect123'],
        ]));
    }

    public function test_instagram_and_telegram_account_provider_survive_parse(): void
    {
        foreach (['INSTAGRAM', 'TELEGRAM', 'WHATSAPP'] as $provider) {
            $parsed = $this->provider->parseEvent([
                'id' => 'evt_'.$provider,
                'type' => 'message.new',
                'account_id' => 'acc_'.$provider,
                'account_provider' => $provider,
                'payload' => [
                    'id' => 'msg_1',
                    'chat_id' => 'chat_1',
                    'text' => 'hi',
                    'sender_id' => 'user_1',
                    'is_sender' => false,
                ],
            ]);

            $this->assertSame($provider, $parsed['account_provider']);
            $this->assertSame($provider, $parsed['data']['provider']);
            $this->assertSame('user_1', $parsed['data']['sender_id']);
        }
    }

    public function test_parse_event_flattens_v2_email_new_nested_email(): void
    {
        // Docs: email.new payload is { folder_id, email }; Email.from is [{ email, display_name }].
        $parsed = $this->provider->parseEvent([
            'id' => 'evt_email_1',
            'type' => 'email.new',
            'account_id' => 'acc_mail',
            'account_provider' => 'GOOGLE',
            'payload' => [
                'folder_id' => 'inbox_1',
                'email' => [
                    'id' => 'email_1',
                    'subject' => 'Re: Hello',
                    'body_plain' => 'Thanks',
                    'from' => [['email' => 'lead@example.com', 'display_name' => 'Lead']],
                    'to' => [['email' => 'me@example.com']],
                    'folders' => ['inbox_1'],
                ],
            ],
        ]);

        $this->assertSame('Thanks', $parsed['text']);
        $this->assertSame('email_1', $parsed['message_id']);
        $this->assertSame('Thanks', $parsed['data']['body_plain']);
        $this->assertSame('lead@example.com', $parsed['data']['from'][0]['email']);
        $this->assertSame('inbox_1', $parsed['data']['folder_id']);
    }

    public function test_parse_event_prefers_plain_text_over_html_body(): void
    {
        $parsed = $this->provider->parseEvent([
            'id' => 'evt_email_html',
            'type' => 'email.new',
            'account_id' => 'acc_mail',
            'account_provider' => 'GOOGLE',
            'payload' => [
                'folder_id' => 'inbox_1',
                'email' => [
                    'id' => 'email_html',
                    'body' => '<p>Thanks <b>a lot</b></p>',
                    'body_plain' => '',
                    'plain_text' => 'Thanks a lot',
                    'from' => [['email' => 'lead@example.com']],
                ],
            ],
        ]);

        $this->assertSame('Thanks a lot', $parsed['text']);
    }
}

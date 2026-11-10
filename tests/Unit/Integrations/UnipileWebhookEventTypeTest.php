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
        $parsed = $this->provider->parseEvent([
            'id' => 'evt_msg_1',
            'type' => 'message.new',
            'account_id' => 'acc_li',
            'account_provider' => 'LINKEDIN',
            'payload' => [
                'id' => 'msg_1',
                'chat_id' => 'chat_1',
                'text' => 'Hello from prospect',
                'is_sender' => false,
            ],
        ]);

        $this->assertSame('chat_1', $parsed['chat_id']);
        $this->assertSame('Hello from prospect', $parsed['text']);
        $this->assertSame('msg_1', $parsed['message_id']);
        $this->assertSame('chat_1', $parsed['data']['chat_id']);
        $this->assertSame('LINKEDIN', $parsed['account_provider']);
    }

    public function test_parse_event_flattens_v2_email_new_nested_email(): void
    {
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
                    'from_attendee' => ['identifier' => 'lead@example.com'],
                ],
            ],
        ]);

        $this->assertSame('Thanks', $parsed['text']);
        $this->assertSame('email_1', $parsed['message_id']);
        $this->assertSame('Thanks', $parsed['data']['body_plain']);
        $this->assertSame('lead@example.com', $parsed['data']['from_attendee']['identifier']);
    }
}

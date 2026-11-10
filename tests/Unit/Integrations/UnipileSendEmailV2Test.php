<?php

namespace Tests\Unit\Integrations;

use App\V2\Integrations\Unipile\UnipileProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UnipileSendEmailV2Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.unipile.base_url', 'https://unipile.test/v2');
        Config::set('services.unipile.api_key', 'test-key');
        Config::set('services.unipile.mock', false);
        Config::set('services.unipile.endpoints.send_email', '/emails/send');
    }

    public function test_send_email_maps_identifier_to_email_and_threads_with_provider_id(): void
    {
        Http::fake([
            'unipile.test/v2/acc_email_1/emails/send' => Http::response([
                'object' => 'EmailSent',
                'id' => 'em_1',
            ], 200),
        ]);

        $provider = app(UnipileProvider::class);
        $provider->sendEmail([
            'to' => [['identifier' => 'vickenconcept@gmail.com', 'display_name' => 'Prospect']],
            'subject' => 'Re: Hello',
            'body' => '<p>hello</p>',
            'reply_to' => 'provider_msg_99',
        ], ['account_id' => 'acc_email_1']);

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://unipile.test/v2/acc_email_1/emails/send') {
                return false;
            }

            $payload = $request->data();

            return ($payload['to'][0]['email'] ?? null) === 'vickenconcept@gmail.com'
                && ($payload['to'][0]['display_name'] ?? null) === 'Prospect'
                && ! array_key_exists('identifier', $payload['to'][0] ?? [])
                && ($payload['html'] ?? null) === '<p>hello</p>'
                && ! array_key_exists('body', $payload)
                && ($payload['reply_to_provider_id'] ?? null) === 'provider_msg_99'
                && ! array_key_exists('reply_to', $payload)
                && ! array_key_exists('account_id', $payload);
        });
    }

    public function test_send_email_keeps_reply_to_header_attendees(): void
    {
        Http::fake([
            'unipile.test/v2/acc_email_2/emails/send' => Http::response([
                'object' => 'EmailSent',
                'id' => 'em_2',
            ], 200),
        ]);

        $provider = app(UnipileProvider::class);
        $provider->sendEmail([
            'to' => [['email' => 'lead@example.com']],
            'subject' => 'Hello',
            'plain_text' => 'Hi',
            'reply_to' => [['identifier' => 'support@example.com']],
        ], ['account_id' => 'acc_email_2']);

        Http::assertSent(function ($request) {
            $payload = $request->data();

            return ($payload['reply_to'][0]['email'] ?? null) === 'support@example.com'
                && ! array_key_exists('reply_to_provider_id', $payload);
        });
    }

    public function test_send_email_string_reply_to_email_stays_header_not_thread_id(): void
    {
        Http::fake([
            'unipile.test/v2/acc_email_3/emails/send' => Http::response([
                'object' => 'EmailSent',
                'id' => 'em_3',
            ], 200),
        ]);

        app(UnipileProvider::class)->sendEmail([
            'to' => [['email' => 'lead@example.com']],
            'subject' => 'Hello',
            'plain_text' => 'Hi',
            'reply_to' => 'support@example.com',
        ], ['account_id' => 'acc_email_3']);

        Http::assertSent(function ($request) {
            $payload = $request->data();

            return ($payload['reply_to'][0]['email'] ?? null) === 'support@example.com'
                && ! array_key_exists('reply_to_provider_id', $payload);
        });
    }

    public function test_mark_chat_read_state_sends_boolean_read_status(): void
    {
        Http::fake([
            'unipile.test/v2/acc_li/chats/chat_9' => Http::response(['object' => 'Chat'], 200),
        ]);

        app(UnipileProvider::class)->markChatReadState('chat_9', true, [
            'account_id' => 'acc_li',
        ]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://unipile.test/v2/acc_li/chats/chat_9'
                && ($request->data()['read_status'] ?? null) === true;
        });
    }
}

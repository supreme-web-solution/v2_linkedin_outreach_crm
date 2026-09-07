<?php

namespace Tests\Unit\V2\Ai;

use App\V2\Ai\Integrations\ZernioClient;
use Tests\TestCase;

class ZernioClientTest extends TestCase
{
    public function test_parses_postback_payload_instead_of_button_title(): void
    {
        $client = app(ZernioClient::class);

        $parsed = $client->parseWebhookEvent([
            'id' => 'evt-btn-1',
            'event' => 'message.received',
            'message' => [
                'id' => 'msg-btn-1',
                'text' => 'Launch',
                'postback' => ['payload' => 'LAUNCH_3'],
                'sender' => ['phoneNumber' => '+2349036802727'],
            ],
            'conversation' => ['id' => 'conv-1', 'participantUsername' => '+2349036802727'],
        ]);

        $this->assertSame('LAUNCH 3', $parsed['text']);
    }

    public function test_parses_launch_hash_from_button_title(): void
    {
        $client = app(ZernioClient::class);

        $parsed = $client->parseWebhookEvent([
            'id' => 'evt-btn-2',
            'event' => 'message.received',
            'message' => [
                'id' => 'msg-btn-2',
                'text' => 'Launch #3',
                'sender' => ['phoneNumber' => '+2349036802727'],
            ],
            'conversation' => ['id' => 'conv-1', 'participantUsername' => '+2349036802727'],
        ]);

        $this->assertSame('LAUNCH 3', $parsed['text']);
    }

    public function test_approval_buttons_use_action_labels_without_plan_id(): void
    {
        $buttons = app(ZernioClient::class)->approvalButtons(3, 'prepare_linkedin_post', [
            'type' => 'linkedin_post',
        ]);

        $this->assertSame('Publish', $buttons[0]['title']);
        $this->assertSame('LAUNCH_3', $buttons[0]['payload']);
        $this->assertSame('Preview', $buttons[1]['title']);
        $this->assertSame('REVIEW_3', $buttons[1]['payload']);
        $this->assertSame('Discard', $buttons[2]['title']);
        $this->assertSame('REJECT_3', $buttons[2]['payload']);
    }

    public function test_default_approval_buttons_are_launch_and_reject(): void
    {
        $buttons = app(ZernioClient::class)->approvalButtons(9);

        $this->assertSame('Launch', $buttons[0]['title']);
        $this->assertSame('LAUNCH_9', $buttons[0]['payload']);
        $this->assertSame('Reject', $buttons[1]['title']);
        $this->assertSame('REJECT_9', $buttons[1]['payload']);
    }

    public function test_chunks_whatsapp_replies_at_zernio_limit(): void
    {
        $client = app(ZernioClient::class);
        $method = new \ReflectionMethod($client, 'chunkMessage');
        $method->setAccessible(true);

        $short = str_repeat('a', 500);
        $this->assertSame([$short], $method->invoke($client, $short));

        $long = str_repeat('b', 2500);
        $chunks = $method->invoke($client, $long);
        $this->assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(1024, mb_strlen($chunk));
            $this->assertNotSame('', trim($chunk));
        }
        $this->assertSame($long, implode('', $chunks));
    }

    public function test_send_typing_indicator_uses_inbox_typing_endpoint(): void
    {
        config([
            'socifusion_ai.zernio.api_key' => 'test-key',
            'socifusion_ai.zernio.base_url' => 'https://api.zernio.test',
            'socifusion_ai.zernio.account_id' => 'acc-1',
            'socifusion_ai.zernio.use_inbox_api' => true,
        ]);

        \Illuminate\Support\Facades\Http::fake([
            'https://api.zernio.test/v1/inbox/conversations/conv-1/typing' => \Illuminate\Support\Facades\Http::response(['success' => true], 200),
        ]);

        $identity = new \App\Models\AiChannelIdentity([
            'meta' => [
                'zernio_conversation_id' => 'conv-1',
                'zernio_account_id' => 'acc-1',
            ],
        ]);

        $sent = app(ZernioClient::class)->sendTypingIndicator($identity);

        $this->assertTrue($sent);
        \Illuminate\Support\Facades\Http::assertSent(function ($request) {
            return $request->url() === 'https://api.zernio.test/v1/inbox/conversations/conv-1/typing'
                && $request['accountId'] === 'acc-1';
        });
    }
}

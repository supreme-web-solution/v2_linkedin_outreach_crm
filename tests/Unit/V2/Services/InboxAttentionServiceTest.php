<?php

namespace Tests\Unit\V2\Services;

use App\Models\User;
use App\Models\V2Conversation;
use App\Models\V2Message;
use App\V2\Services\InboxAttentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboxAttentionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_read_thread_still_needs_attention_when_latest_message_is_inbound(): void
    {
        $user = User::factory()->create();
        $conversation = V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'email',
            'provider_chat_id' => 'prospect@example.com',
            'status' => 'active',
            'last_read_at' => now(),
            'meta' => ['source' => 'unified_inbox', 'prospect_name' => 'Prospect'],
        ]);

        V2Message::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'body' => 'Hello there',
            'sent_at' => now()->subHour(),
        ]);

        V2Message::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'body' => 'Thanks — tell me more',
            'received_at' => now()->subMinutes(5),
        ]);

        $service = new InboxAttentionService();

        $map = $service->needsAttentionMap(collect([$conversation]));
        $this->assertTrue($map[$conversation->id] ?? false);
        $this->assertTrue($service->needsAttention($conversation));
    }

    public function test_thread_does_not_need_attention_after_outbound_reply(): void
    {
        $user = User::factory()->create();
        $conversation = V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'email',
            'provider_chat_id' => 'prospect@example.com',
            'status' => 'active',
            'meta' => ['source' => 'unified_inbox'],
        ]);

        V2Message::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'body' => 'Thanks',
            'received_at' => now()->subHour(),
        ]);

        V2Message::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'body' => 'Happy to help',
            'sent_at' => now(),
        ]);

        $service = new InboxAttentionService();

        $this->assertFalse($service->needsAttention($conversation));
    }
}

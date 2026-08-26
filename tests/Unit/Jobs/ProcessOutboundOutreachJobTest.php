<?php

namespace Tests\Unit\Jobs;

use App\Jobs\V2\ProcessOutboundOutreachJob;
use App\Models\User;
use App\Models\V2Conversation;
use App\Models\V2Message;
use App\V2\Contracts\Providers\MessagingProviderInterface;
use App\V2\Integrations\ProviderManager;
use App\V2\Integrations\Unipile\UnipileException;
use App\V2\Services\OutreachPersistenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessOutboundOutreachJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.unipile_pacing.daily_messages', 0);
        config()->set('services.unipile_pacing.daily_new_chats', 0);
        config()->set('v2_provider_policy.default_provider', 'unipile');
    }

    public function test_message_send_recovers_from_stale_chat_via_start_chat(): void
    {
        $user = User::factory()->create();

        $conversation = V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_chat_id' => 'DCAnJZ6eUS2jwC4B1raqOA',
            'status' => 'active',
            'meta' => [
                'organization_id' => 1,
                'attendee_ids' => ['ACoAAStaleRecipient'],
            ],
        ]);

        $message = V2Message::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'body' => 'Would you be open to a quick call?',
            'meta' => [
                'action' => 'message',
                'status' => 'queued',
            ],
        ]);

        $messaging = new class implements MessagingProviderInterface
        {
            public int $startChatCalls = 0;

            public function listChats(array $filters = [], array $context = []): array
            {
                return [];
            }

            public function listMessages(string $chatId, array $filters = [], array $context = []): array
            {
                return [];
            }

            public function sendMessage(string $chatId, array $payload, array $context = []): array
            {
                throw new UnipileException(
                    'Messaging error (HTTP 404): Chat not found',
                    404,
                    ['response' => ['detail' => 'Chat not found']]
                );
            }

            public function startChat(array $payload, array $context = []): array
            {
                $this->startChatCalls++;

                return ['chat_id' => 'fresh-chat-id-123', 'id' => 'fresh-chat-id-123'];
            }

            public function markChatReadState(string $chatId, bool $isRead, array $context = []): array
            {
                return [];
            }
        };

        $this->app->instance(ProviderManager::class, new ProviderManager(['unipile' => $messaging]));

        $job = new ProcessOutboundOutreachJob(
            'message',
            $user->id,
            1,
            $conversation->id,
            $message->id,
            [
                'chat_id' => 'DCAnJZ6eUS2jwC4B1raqOA',
                'text' => 'Would you be open to a quick call?',
            ]
        );

        $job->handle(
            app(ProviderManager::class),
            app(OutreachPersistenceService::class),
            app(\App\V2\Campaign\CampaignLinkedInGuard::class),
        );

        $conversation->refresh();
        $message->refresh();

        $this->assertSame(1, $messaging->startChatCalls);
        $this->assertSame('DCAnJZ6eUS2jwC4B1raqOA', $conversation->meta['invalid_provider_chat_id'] ?? null);
        $this->assertSame('fresh-chat-id-123', $conversation->provider_chat_id);
        $this->assertSame('sent', $message->meta['status'] ?? null);
        $this->assertTrue($message->meta['chat_recovery_attempted'] ?? false);
    }
}

<?php

namespace Tests\Feature;

use App\Models\AiChannelIdentity;
use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\Jobs\V2\ProcessZernioInboundMessageJob;
use App\V2\Ai\Integrations\ZernioClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ZernioWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('socifusion_ai.zernio.webhook_secret', 'test-webhook-secret');
        config()->set('socifusion_ai.zernio.use_inbox_api', false);
    }

    public function test_rejects_invalid_signature(): void
    {
        $payload = json_encode(['event' => 'webhook.test', 'id' => 'evt-1'], JSON_THROW_ON_ERROR);

        $response = $this->signedPost($payload, 'bad-signature');

        $response->assertUnauthorized();
    }

    public function test_accepts_webhook_test_event(): void
    {
        $payload = json_encode([
            'id' => 'evt-test-1',
            'event' => 'webhook.test',
            'message' => 'hello',
        ], JSON_THROW_ON_ERROR);

        $response = $this->signedPost($payload);

        $response->assertOk()->assertJson(['ok' => true, 'test' => true]);
    }

    public function test_deduplicates_webhook_events(): void
    {
        $payload = json_encode([
            'id' => 'evt-dup-1',
            'event' => 'message.delivered',
        ], JSON_THROW_ON_ERROR);

        $this->signedPost($payload)->assertOk();
        $this->signedPost($payload)
            ->assertOk()
            ->assertJson(['duplicate' => true]);
    }

    public function test_links_whatsapp_identity_from_link_code(): void
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Test Org',
            'slug' => 'test-org-zernio',
            'owner_id' => $user->id,
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $user->forceFill(['current_organization_id' => $org->id])->save();

        \App\Models\AiChannelLinkCode::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'channel' => 'whatsapp',
            'code' => 'LINK-TEST1',
            'expires_at' => now()->addMinutes(15),
        ]);

        $payload = json_encode([
            'id' => 'evt-link-1',
            'event' => 'message.received',
            'message' => ['id' => 'msg-1', 'text' => 'LINK-TEST1'],
            'conversation' => [
                'id' => 'conv-abc',
                'contact' => ['phone' => '+15551234567'],
            ],
            'account' => ['id' => 'acc-abc'],
        ], JSON_THROW_ON_ERROR);

        $this->signedPost($payload)->assertOk()->assertJson(['linked' => true]);

        $identity = AiChannelIdentity::query()->where('external_id', '+15551234567')->first();
        $this->assertNotNull($identity);
        $this->assertSame('conv-abc', $identity->meta['zernio_conversation_id'] ?? null);
        $this->assertSame('acc-abc', $identity->meta['zernio_account_id'] ?? null);
    }

    public function test_links_whatsapp_from_zernio_message_received_payload(): void
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Test Org Zernio Real',
            'slug' => 'test-org-zernio-real',
            'owner_id' => $user->id,
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $user->forceFill(['current_organization_id' => $org->id])->save();

        \App\Models\AiChannelLinkCode::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'channel' => 'whatsapp',
            'code' => 'LINK-Y9WFT',
            'expires_at' => now()->addMinutes(15),
        ]);

        $payload = json_encode([
            'id' => '9952e070-18a7-41a0-aa4a-29b3c9737341',
            'event' => 'message.received',
            'message' => [
                'id' => '6a9d8fdd4480ec0bd8d8c356',
                'conversationId' => '6a9d7f24622c4eade28e5fa8',
                'platform' => 'whatsapp',
                'direction' => 'incoming',
                'text' => 'LINK-Y9WFT',
                'sender' => [
                    'id' => '2349036802727',
                    'name' => 'William Victor',
                    'phoneNumber' => '+2349036802727',
                ],
            ],
            'conversation' => [
                'id' => '6a9d7f24622c4eade28e5fa8',
                'platformConversationId' => '2349036802727',
                'participantId' => '2349036802727',
                'participantUsername' => '+2349036802727',
            ],
            'account' => [
                'id' => '6a9d7de877555aae01e560f6',
                'platform' => 'whatsapp',
            ],
        ], JSON_THROW_ON_ERROR);

        $this->signedPost($payload)->assertOk()->assertJson(['linked' => true]);

        $identity = AiChannelIdentity::query()->where('external_id', '+2349036802727')->first();
        $this->assertNotNull($identity);
        $this->assertSame($user->id, $identity->user_id);
    }

    public function test_queues_linked_whatsapp_messages_for_async_processing(): void
    {
        Queue::fake();
        config()->set('socifusion_ai.zernio.queue_inbound', true);

        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Queue Org',
            'slug' => 'queue-org-zernio',
            'owner_id' => $user->id,
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $identity = AiChannelIdentity::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'channel' => 'whatsapp',
            'external_id' => '+15551234567',
            'status' => 'active',
            'verified_at' => now(),
        ]);

        $payload = json_encode([
            'id' => 'evt-queue-1',
            'event' => 'message.received',
            'message' => [
                'id' => 'msg-queue-1',
                'text' => 'brief',
                'sender' => ['phoneNumber' => '+15551234567'],
            ],
            'conversation' => ['id' => 'conv-queue-1'],
            'account' => ['id' => 'acc-queue-1'],
        ], JSON_THROW_ON_ERROR);

        $this->signedPost($payload)->assertOk()->assertJson(['queued' => true]);

        Queue::assertPushed(ProcessZernioInboundMessageJob::class, function (ProcessZernioInboundMessageJob $job) use ($identity, $user, $org) {
            return $job->identityId === $identity->id
                && $job->userId === $user->id
                && $job->organizationId === $org->id
                && $job->message === 'brief';
        });
    }

    private function signedPost(string $rawPayload, ?string $signature = null)
    {
        $signature ??= app(ZernioClient::class)->computeSignature($rawPayload);

        return $this->call(
            'POST',
            '/api/v2/provider-events/zernio',
            server: $this->transformHeadersToServerVars([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-Zernio-Signature' => $signature,
            ]),
            content: $rawPayload,
        );
    }
}

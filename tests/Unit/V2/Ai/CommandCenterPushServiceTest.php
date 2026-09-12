<?php

namespace Tests\Unit\V2\Ai;

use App\Models\AiChannelIdentity;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Ai\Services\CommandCenterPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CommandCenterPushServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_posts_assistant_message_with_approval_meta(): void
    {
        config()->set('socifusion_ai.attention_digest.mirror_whatsapp', false);

        [$user, $org] = $this->fixtures();

        $message = app(CommandCenterPushService::class)->postAssistant(
            $user,
            $org->id,
            '📋 Test digest',
            ['source' => 'attention_digest'],
            42,
        );

        $this->assertSame('assistant', $message->role);
        $this->assertSame(42, $message->meta['approval_id'] ?? null);
        $this->assertTrue(
            AiMessage::query()->whereKey($message->id)->exists()
        );
    }

    public function test_mirrors_to_whatsapp_when_linked(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        config()->set('socifusion_ai.zernio.api_key', 'test-key');
        config()->set('socifusion_ai.zernio.account_id', 'acc-1');
        config()->set('socifusion_ai.attention_digest.mirror_whatsapp', true);

        [$user, $org] = $this->fixtures();

        AiChannelIdentity::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'channel' => 'whatsapp',
            'external_id' => '+15551234567',
            'status' => 'active',
            'meta' => [
                'zernio_conversation_id' => 'conv-1',
                'zernio_account_id' => 'acc-1',
            ],
        ]);

        app(CommandCenterPushService::class)->postAssistant(
            $user,
            $org->id,
            '📋 Digest with action',
            ['source' => 'attention_digest'],
            99,
        );

        Http::assertSentCount(1);
    }

    /**
     * @return array{0: User, 1: V2Organization}
     */
    private function fixtures(): array
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Push Org',
            'slug' => 'push-'.uniqid(),
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
            'title' => 'Command Center',
        ]);

        return [$user, $org];
    }
}

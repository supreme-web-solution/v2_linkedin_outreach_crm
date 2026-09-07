<?php

namespace Tests\Feature\Web;

use App\Jobs\V2\ProcessWebAiChatJob;
use App\Models\AiMessage;
use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Ai\Services\AgentOrchestrator;
use App\V2\Ai\Services\CommandCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AiEmployeeWebChatQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('socifusion_ai.web_chat_queue', true);
        config()->set('socifusion_ai.enabled', true);
        config()->set('socifusion_ai.kill_switch', false);
    }

    public function test_chat_queues_llm_turn_and_returns_202(): void
    {
        Queue::fake();
        [$user, $org] = $this->userWithOrg();

        $response = $this->actingAs($user)->postJson('/ai-employee/chat', [
            'message' => 'Book meetings with SaaS founders',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('pending', true)
            ->assertJsonStructure([
                'conversation_id',
                'after_message_id',
                'user_message' => ['id', 'role', 'content'],
            ]);

        $this->assertDatabaseHas('ai_messages', [
            'id' => $response->json('after_message_id'),
            'role' => 'user',
            'content' => 'Book meetings with SaaS founders',
        ]);

        Queue::assertPushed(ProcessWebAiChatJob::class, function (ProcessWebAiChatJob $job) use ($user, $org, $response) {
            return $job->userId === $user->id
                && $job->organizationId === $org->id
                && $job->conversationId === (int) $response->json('conversation_id')
                && $job->userMessageId === (int) $response->json('after_message_id');
        });
    }

    public function test_messages_after_id_returns_only_newer_rows(): void
    {
        [$user, $org] = $this->userWithOrg();
        $conversation = app(CommandCenterService::class)->conversation($user, $org->id);

        $first = AiMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'hello',
            'meta' => ['channel' => 'web'],
        ]);
        $second = AiMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'hi there',
            'meta' => ['channel' => 'web'],
        ]);

        $response = $this->actingAs($user)->getJson(
            '/ai-employee/messages?conversation_id='.$conversation->id.'&after_id='.$first->id
        );

        $response->assertOk();
        $ids = collect($response->json('messages'))->pluck('id')->all();
        $this->assertSame([$second->id], $ids);
    }

    public function test_continue_queued_web_turn_writes_assistant_reply(): void
    {
        [$user, $org] = $this->userWithOrg();
        $conversation = app(CommandCenterService::class)->conversation($user, $org->id);

        $userMessage = AiMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'status',
            'meta' => ['channel' => 'web', 'queued' => true],
        ]);

        $result = app(AgentOrchestrator::class)->continueQueuedWebTurn(
            user: $user,
            organizationId: $org->id,
            conversationId: (int) $conversation->id,
            userMessageId: (int) $userMessage->id,
            promptMessage: 'status',
        );

        $this->assertArrayHasKey('reply', $result);
        $this->assertTrue(
            AiMessage::query()
                ->where('conversation_id', $conversation->id)
                ->where('role', 'assistant')
                ->where('id', '>', $userMessage->id)
                ->exists()
        );
    }

    /**
     * @return array{0:User, 1:V2Organization}
     */
    private function userWithOrg(): array
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Test Org',
            'slug' => 'test-org-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        $user->forceFill(['current_organization_id' => $org->id])->save();
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        return [$user, $org];
    }
}

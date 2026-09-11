<?php

namespace Tests\Feature\Web;

use App\Jobs\V2\ProcessWebAiChatJob;
use App\Jobs\V2\RunWebAgentTurnJob;
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

    public function test_process_web_chat_job_dispatches_agent_turn_job(): void
    {
        Queue::fake([RunWebAgentTurnJob::class]);
        [$user, $org] = $this->userWithOrg();
        $conversation = app(CommandCenterService::class)->conversation($user, $org->id);

        $job = new ProcessWebAiChatJob(
            $user->id,
            $org->id,
            (int) $conversation->id,
            99,
            'find customers',
        );

        $job->handle(app(\App\V2\Ai\Services\WebChatProcessingService::class));

        Queue::assertPushed(RunWebAgentTurnJob::class, function (RunWebAgentTurnJob $agentJob) use ($user, $org, $conversation) {
            return $agentJob->userId === $user->id
                && $agentJob->organizationId === $org->id
                && $agentJob->conversationId === (int) $conversation->id
                && $agentJob->userMessageId === 99;
        });
    }

    public function test_widget_bootstrap_returns_pending_turn(): void
    {
        [$user, $org] = $this->userWithOrg();
        $conversation = app(CommandCenterService::class)->conversation($user, $org->id);

        $userMessage = AiMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'find customers',
            'meta' => ['channel' => 'web', 'queued' => true],
        ]);

        app(\App\V2\Ai\Services\WebChatProcessingService::class)->markPending($conversation, (int) $userMessage->id);
        app(\App\V2\Ai\Services\WebChatProcessingService::class)->start($conversation->fresh(), 'Searching prospects…');

        $response = $this->actingAs($user)->getJson('/ai-employee/widget/bootstrap');

        $response->assertOk()
            ->assertJsonPath('pending_turn.after_message_id', $userMessage->id)
            ->assertJsonPath('processing.label', 'Searching prospects…');
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

        $conversation->refresh();
        $this->assertNull(app(\App\V2\Ai\Services\WebChatProcessingService::class)->snapshot($conversation));
    }

    public function test_stale_pending_turn_is_redispatched_when_no_queue_job(): void
    {
        [$user, $org] = $this->userWithOrg();
        $conversation = app(CommandCenterService::class)->conversation($user, $org->id);

        $userMessage = AiMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'find customers',
            'meta' => ['channel' => 'web', 'queued' => true],
            'created_at' => now()->subMinutes(5),
        ]);

        $staleAt = now()->subMinutes(5)->toIso8601String();
        $conversation->forceFill([
            'meta' => [
                'web_chat_pending' => [
                    'user_message_id' => $userMessage->id,
                    'prompt_message' => 'find customers',
                    'organization_id' => $org->id,
                    'queued_at' => $staleAt,
                    'redispatch_attempts' => 0,
                ],
                'web_chat_processing' => [
                    'active' => true,
                    'stage' => 'thinking',
                    'label' => 'Thinking…',
                    'updated_at' => $staleAt,
                ],
            ],
        ])->save();

        config()->set('socifusion_ai.web_chat_stale_seconds', 30);

        Queue::fake();

        $response = $this->actingAs($user)->getJson('/ai-employee/widget/bootstrap');

        $response->assertOk()
            ->assertJsonPath('pending_turn.processing.label', 'Resuming…');

        Queue::assertPushed(RunWebAgentTurnJob::class, function (RunWebAgentTurnJob $job) use ($user, $org, $conversation, $userMessage) {
            return $job->userId === $user->id
                && $job->organizationId === $org->id
                && $job->conversationId === (int) $conversation->id
                && $job->userMessageId === (int) $userMessage->id;
        });
    }

    public function test_fail_queued_web_turn_writes_error_reply_and_clears_processing(): void
    {
        [$user, $org] = $this->userWithOrg();
        $conversation = app(CommandCenterService::class)->conversation($user, $org->id);

        app(\App\V2\Ai\Services\WebChatProcessingService::class)->start($conversation, 'Thinking…');

        $userMessage = AiMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'find customers',
            'meta' => ['channel' => 'web', 'queued' => true],
        ]);

        app(AgentOrchestrator::class)->failQueuedWebTurn(
            user: $user,
            organizationId: $org->id,
            conversationId: (int) $conversation->id,
            userMessageId: (int) $userMessage->id,
            exception: new \RuntimeException('timeout'),
        );

        $conversation->refresh();
        $this->assertNull(app(\App\V2\Ai\Services\WebChatProcessingService::class)->snapshot($conversation));
        $this->assertTrue(
            AiMessage::query()
                ->where('conversation_id', $conversation->id)
                ->where('role', 'assistant')
                ->where('id', '>', $userMessage->id)
                ->where('content', 'like', '%too long%')
                ->exists()
        );
    }

    public function test_messages_after_id_includes_processing_snapshot(): void
    {
        [$user, $org] = $this->userWithOrg();
        $conversation = app(CommandCenterService::class)->conversation($user, $org->id);
        app(\App\V2\Ai\Services\WebChatProcessingService::class)->start($conversation, 'Searching prospects…');

        $first = AiMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'hello',
            'meta' => ['channel' => 'web'],
        ]);

        $response = $this->actingAs($user)->getJson(
            '/ai-employee/messages?conversation_id='.$conversation->id.'&after_id='.$first->id
        );

        $response->assertOk()
            ->assertJsonPath('processing.active', true)
            ->assertJsonPath('processing.label', 'Searching prospects…');
    }

    public function test_clear_chat_archives_thread_and_starts_fresh(): void
    {
        [$user, $org] = $this->userWithOrg();
        $commandCenter = app(CommandCenterService::class);
        $old = $commandCenter->conversation($user, $org->id);

        AiMessage::query()->create([
            'conversation_id' => $old->id,
            'role' => 'user',
            'content' => 'old message',
            'meta' => ['channel' => 'web'],
        ]);

        $pending = \App\Models\AiActionApproval::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'conversation_id' => $old->id,
            'tool' => 'propose_strategy',
            'permission' => 'prepare',
            'status' => 'pending',
            'payload' => ['type' => 'strategy', 'goal' => 'Sell SociFusion'],
        ]);

        $response = $this->actingAs($user)->postJson('/ai-employee/chat/clear');

        $response->assertOk()
            ->assertJsonPath('has_older_messages', false)
            ->assertJsonStructure(['conversation_id', 'archived_conversation_id', 'messages', 'pending_approvals']);

        $this->assertNotSame($old->id, (int) $response->json('conversation_id'));
        $this->assertSame($old->id, (int) $response->json('archived_conversation_id'));

        $this->assertDatabaseHas('ai_conversations', [
            'id' => $old->id,
            'status' => 'archived',
        ]);
        $this->assertDatabaseHas('ai_conversations', [
            'id' => $response->json('conversation_id'),
            'status' => 'open',
        ]);
        $this->assertDatabaseHas('ai_messages', [
            'conversation_id' => $old->id,
            'content' => 'old message',
        ]);
        $this->assertDatabaseHas('ai_action_approvals', [
            'id' => $pending->id,
            'status' => 'pending',
        ]);

        $fresh = $commandCenter->conversation($user, $org->id);
        $this->assertSame((int) $response->json('conversation_id'), $fresh->id);
        $this->assertSame(1, count($response->json('messages')));
        $this->assertSame('assistant', $response->json('messages.0.role'));
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

<?php

namespace Tests\Feature\V2\Ai;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiWorkflowRun;
use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Ai\Services\WebChatProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebChatWorkflowProcessingTest extends TestCase
{
    use RefreshDatabase;

    public function test_turn_not_complete_while_workflow_still_running(): void
    {
        [$user, $org, $conversation] = $this->conversationWithUser();

        $userMessage = AiMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Find 30 agency owners',
            'meta' => ['queued' => true, 'channel' => 'web'],
        ]);

        AiMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Discovery is in progress…',
            'meta' => ['channel' => 'web'],
        ]);

        AiWorkflowRun::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'conversation_id' => $conversation->id,
            'status' => 'running',
            'required_outcome' => 'send_now',
            'plan' => ['required_outcome' => 'send_now'],
            'meta' => [],
        ]);

        $processing = app(WebChatProcessingService::class);
        $processing->markPending($conversation, $userMessage->id);

        $this->assertFalse($processing->conversationTurnComplete($conversation, $userMessage->id));

        $pending = $processing->pendingTurnSnapshot($conversation->fresh());
        $this->assertNotNull($pending);
        $this->assertSame($userMessage->id, $pending['after_message_id']);
    }

    public function test_turn_complete_when_workflow_waiting_for_launch(): void
    {
        [$user, $org, $conversation] = $this->conversationWithUser();

        $userMessage = AiMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Find 30 agency owners',
            'meta' => ['queued' => true, 'channel' => 'web'],
        ]);

        AiMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Send LAUNCH #99 when ready.',
            'meta' => ['channel' => 'web', 'source' => 'workflow_runtime'],
        ]);

        AiWorkflowRun::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'conversation_id' => $conversation->id,
            'status' => 'waiting',
            'required_outcome' => 'send_now',
            'plan' => ['required_outcome' => 'send_now'],
            'meta' => ['prepared' => true],
        ]);

        $processing = app(WebChatProcessingService::class);
        $processing->markPending($conversation, $userMessage->id);

        $this->assertTrue($processing->conversationTurnComplete($conversation, $userMessage->id));
    }

    /**
     * @return array{0:User, 1:V2Organization, 2:AiConversation}
     */
    private function conversationWithUser(): array
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Chat Org '.uniqid(),
            'slug' => 'chat-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        $user->forceFill(['current_organization_id' => $org->id])->save();
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $conversation = AiConversation::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'title' => 'Command Center',
        ]);

        return [$user->fresh(), $org, $conversation];
    }
}

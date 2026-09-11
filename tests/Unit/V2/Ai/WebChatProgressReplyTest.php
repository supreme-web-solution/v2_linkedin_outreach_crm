<?php

namespace Tests\Unit\V2\Ai;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Ai\Services\WebChatProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebChatProgressReplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_progress_reply_does_not_complete_pending_turn(): void
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Progress Org',
            'slug' => 'progress-org-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $conversation = AiConversation::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'channel' => 'command_center',
            'status' => 'open',
            'title' => 'Command Center',
        ]);

        $userMessage = AiMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'find customers',
            'meta' => ['channel' => 'web', 'queued' => true],
        ]);

        $processing = app(WebChatProcessingService::class);
        $processing->markPending($conversation, (int) $userMessage->id);
        $processing->start($conversation, 'Thinking…');

        $processing->postProgressReply(
            $conversation->fresh(),
            "LinkedIn done — saved 50 prospects.\n\nSearching Instagram profiles now.",
            isFinal: false,
            nextLabel: 'Searching Instagram profiles…',
        );

        $this->assertNotNull($processing->pendingTurnSnapshot($conversation->fresh()));
        $this->assertFalse($processing->isFinalAssistantReply(
            "LinkedIn done — saved 50 prospects.\n\nSearching Instagram profiles now.",
            ['progress' => true],
        ));

        $processing->postProgressReply(
            $conversation->fresh(),
            'Saved 100 prospects total.',
            isFinal: true,
        );

        $this->assertTrue($processing->isFinalAssistantReply(
            'Saved 100 prospects total.',
            ['progress' => false],
        ));
    }
}

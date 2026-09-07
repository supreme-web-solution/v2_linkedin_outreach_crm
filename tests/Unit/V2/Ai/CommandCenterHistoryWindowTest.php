<?php

namespace Tests\Unit\V2\Ai;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Ai\Services\CommandCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommandCenterHistoryWindowTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_window_returns_latest_messages_and_has_older_flag(): void
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'History Org',
            'slug' => 'history-org-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $conversation = AiConversation::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'channel' => 'command_center',
            'status' => 'open',
            'title' => 'Command Center',
        ]);

        for ($i = 1; $i <= 55; $i++) {
            AiMessage::query()->create([
                'conversation_id' => $conversation->id,
                'role' => $i % 2 === 0 ? 'assistant' : 'user',
                'content' => "Message {$i}",
            ]);
        }

        $service = app(CommandCenterService::class);
        $window = $service->historyWindow($conversation, null, 50);

        $this->assertCount(50, $window['messages']);
        $this->assertTrue($window['has_older']);
        $this->assertSame('Message 6', $window['messages']->first()->content);
        $this->assertSame('Message 55', $window['messages']->last()->content);

        $older = $service->historyWindow($conversation, (int) $window['messages']->first()->id, 50);
        $this->assertCount(5, $older['messages']);
        $this->assertFalse($older['has_older']);
    }
}

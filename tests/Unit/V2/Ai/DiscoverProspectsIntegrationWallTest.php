<?php

namespace Tests\Unit\V2\Ai;

use App\Ai\Tools\DiscoverProspectsTool;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Ai\AgentContext;
use App\V2\Ai\Enums\AiAutonomyLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class DiscoverProspectsIntegrationWallTest extends TestCase
{
    use RefreshDatabase;

    public function test_discover_blocks_when_no_searchable_channel(): void
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Wall Org',
            'slug' => 'wall-org-'.uniqid(),
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
        AiMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Find 40 SaaS founders',
        ]);

        $settings = app(\App\V2\Ai\Services\AiEmployeeSettingsService::class)->for($user, $org->id);
        $settings->autonomy_level = AiAutonomyLevel::Assisted->value;
        $settings->save();

        $tool = new DiscoverProspectsTool(new AgentContext(
            user: $user,
            organizationId: $org->id,
            settings: $settings->fresh(),
            conversation: $conversation,
            channel: 'web',
        ));
        $run = new \ReflectionMethod(DiscoverProspectsTool::class, 'run');
        $run->setAccessible(true);

        $result = $run->invoke($tool, new Request([
            'query' => 'SaaS founders',
            'target_count' => 40,
        ]));

        $this->assertTrue((bool) ($result['blocked'] ?? false));
        $this->assertSame('no_searchable_channel', $result['reason'] ?? null);
        $this->assertStringContainsString('LinkedIn', (string) ($result['message'] ?? ''));
    }
}

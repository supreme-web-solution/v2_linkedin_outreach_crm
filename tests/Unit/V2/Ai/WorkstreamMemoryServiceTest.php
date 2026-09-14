<?php

namespace Tests\Unit\V2\Ai;

use App\Models\AiConversation;
use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Ai\Services\WorkstreamMemoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkstreamMemoryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_remembers_turn_plan_and_builds_prompt_block(): void
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'WS Org',
            'slug' => 'ws-org-'.uniqid(),
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
            'channel' => 'web',
            'status' => 'active',
            'meta' => [],
        ]);

        $service = app(WorkstreamMemoryService::class);
        $service->rememberFromTurnPlan($conversation, [
            'interpreted_brief' => 'Find 10 CTOs and stage LinkedIn outreach',
            'required_outcome' => 'setup_only',
            'constraints' => [
                'preferred_channels' => ['linkedin', 'email'],
                'audience_ref' => 'CTO SaaS',
                'list_hash' => 'abc123',
            ],
        ], 'build this');

        $conversation->refresh();
        $block = $service->promptBlock($conversation);

        $this->assertStringContainsString('Workstream memory', $block);
        $this->assertStringContainsString('abc123', $block);
        $this->assertStringContainsString('CTO SaaS', $block);
        $this->assertSame('abc123', $service->current($conversation)['list_hash'] ?? null);
    }
}

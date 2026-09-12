<?php

namespace Tests\Unit\V2\Ai;

use App\Models\AiConversation;
use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Ai\Services\WorkflowRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowRunServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_planned_workflow_run_with_scope_hash(): void
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Workflow Org',
            'slug' => 'workflow-org-'.uniqid(),
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $user->forceFill(['current_organization_id' => $org->id])->save();
        $conversation = AiConversation::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'channel' => 'web',
            'status' => 'active',
        ]);

        $run = app(WorkflowRunService::class)->createPlannedRun(
            $user,
            $org->id,
            $conversation,
            ['goal' => 'outreach'],
            ['target_count' => 50, 'channels' => ['linkedin']],
        );

        $this->assertSame('planned', $run->status);
        $this->assertNotEmpty($run->scope_hash);
    }
}

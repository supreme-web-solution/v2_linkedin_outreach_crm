<?php

namespace Tests\Unit\V2\Ai;

use App\Models\AiActionApproval;
use App\Models\User;
use App\Models\V2OutreachCampaign;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Ai\Enums\AiAutonomyLevel;
use App\V2\Ai\Services\AgentOrchestrator;
use App\V2\Ai\Services\AiEmployeeSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class AgentOrchestratorAutoLaunchGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_setup_only_prompt_prevents_autopilot_auto_launch(): void
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Autopilot Guard Org',
            'slug' => 'autopilot-guard-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $user->forceFill(['current_organization_id' => $org->id])->save();

        app(AiEmployeeSettingsService::class)->updateForUser(
            $user,
            $org->id,
            ['autonomy_level' => AiAutonomyLevel::Autopilot->value],
            allowAutonomous: false,
        );

        $approval = AiActionApproval::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'tool' => 'draft_campaign_plan',
            'permission' => 'prepare',
            'status' => 'pending',
            'payload' => [
                'type' => 'campaign',
                'goal' => 'Create campaign but do not send yet',
                'audience' => 'IG: custom software (10)',
                'channels' => 'Instagram',
                'preferred_channels' => 'Instagram',
                'setup_only' => true,
            ],
        ]);

        $reply = 'Plan staged.';
        $service = app(AgentOrchestrator::class);
        $method = new ReflectionMethod($service, 'maybeAutoLaunchOutreachPlan');
        $method->setAccessible(true);

        /** @var \App\Models\AiActionApproval|null $result */
        $result = $method->invokeArgs($service, [
            $user,
            $org->id,
            &$reply,
            $approval,
            'create the campaign but dont send it yet',
        ]);

        $this->assertNotNull($result);
        $this->assertSame($approval->id, $result->id);
        $this->assertSame('pending', $approval->fresh()->status);
        $this->assertSame('Plan staged.', $reply);
        $this->assertSame(0, V2OutreachCampaign::query()->count());
    }
}


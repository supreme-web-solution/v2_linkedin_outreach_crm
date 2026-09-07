<?php

namespace Tests\Unit\V2\Ai;

use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Ai\Enums\AiAutonomyLevel;
use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\AiEmployeeSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiEmployeeExecuteAllowlistTest extends TestCase
{
    use RefreshDatabase;

    public function test_autopilot_may_execute_default_allowlisted_tools(): void
    {
        [$user, $org] = $this->userWithOrg();
        $service = app(AiEmployeeSettingsService::class);

        $service->updateForUser($user, $org->id, [
            'autonomy_level' => AiAutonomyLevel::Autopilot->value,
            'allowed_execute_tools' => [],
        ]);

        $settings = $service->for($user, $org->id);

        $this->assertTrue($service->mayRun($settings, AiToolPermission::Execute, 'send_inbox_reply'));
        $this->assertTrue($service->mayRun($settings, AiToolPermission::Execute, 'move_lead_to_nurture'));
        $this->assertFalse($service->mayRun($settings, AiToolPermission::Execute, 'unknown_tool'));
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

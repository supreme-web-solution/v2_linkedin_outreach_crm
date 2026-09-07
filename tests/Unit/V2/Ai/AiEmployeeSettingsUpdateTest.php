<?php

namespace Tests\Unit\V2\Ai;

use App\Models\AiEmployeeSetting;
use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Ai\Enums\AiAutonomyLevel;
use App\V2\Ai\Services\AiEmployeeSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class AiEmployeeSettingsUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_to_assisted_for_new_org(): void
    {
        [$user, $org] = $this->userWithOrg();

        $settings = app(AiEmployeeSettingsService::class)->for($user, $org->id);

        $this->assertSame(AiAutonomyLevel::Assisted->value, (int) $settings->autonomy_level);
    }

    public function test_user_can_save_autopilot_level(): void
    {
        [$user, $org] = $this->userWithOrg();

        $updated = app(AiEmployeeSettingsService::class)->updateForUser(
            $user,
            $org->id,
            ['autonomy_level' => AiAutonomyLevel::Autopilot->value],
            allowAutonomous: false,
        );

        $this->assertSame(AiAutonomyLevel::Autopilot->value, (int) $updated->autonomy_level);
        $this->assertSame($user->id, $updated->user_id);
    }

    public function test_non_admin_cannot_enable_autonomous(): void
    {
        [$user, $org] = $this->userWithOrg();

        $this->expectException(InvalidArgumentException::class);

        app(AiEmployeeSettingsService::class)->updateForUser(
            $user,
            $org->id,
            ['autonomy_level' => AiAutonomyLevel::Autonomous->value],
            allowAutonomous: false,
        );
    }

    public function test_user_override_takes_precedence_over_org_default(): void
    {
        [$user, $org] = $this->userWithOrg();

        AiEmployeeSetting::query()->create([
            'organization_id' => $org->id,
            'user_id' => null,
            'enabled' => true,
            'kill_switch' => false,
            'autonomy_level' => AiAutonomyLevel::Copilot->value,
            'employee_name' => 'Alex',
        ]);

        app(AiEmployeeSettingsService::class)->updateForUser(
            $user,
            $org->id,
            ['autonomy_level' => AiAutonomyLevel::Assisted->value],
            allowAutonomous: false,
        );

        $settings = app(AiEmployeeSettingsService::class)->for($user, $org->id);

        $this->assertSame(AiAutonomyLevel::Assisted->value, (int) $settings->autonomy_level);
        $this->assertSame($user->id, $settings->user_id);
    }

    public function test_enable_unless_user_opted_out_turns_alex_on(): void
    {
        [$user, $org] = $this->userWithOrg();

        AiEmployeeSetting::query()->create([
            'organization_id' => $org->id,
            'user_id' => null,
            'enabled' => false,
            'kill_switch' => false,
            'autonomy_level' => AiAutonomyLevel::Assisted->value,
            'employee_name' => 'Alex',
        ]);

        $updated = app(AiEmployeeSettingsService::class)->enableUnlessUserOptedOut($user, $org->id);

        $this->assertTrue($updated->enabled);
        $this->assertSame($user->id, $updated->user_id);
    }

    public function test_user_opt_out_is_respected(): void
    {
        [$user, $org] = $this->userWithOrg();

        app(AiEmployeeSettingsService::class)->updateForUser(
            $user,
            $org->id,
            ['autonomy_level' => AiAutonomyLevel::Assisted->value, 'enabled' => false],
            allowAutonomous: false,
        );

        $settings = app(AiEmployeeSettingsService::class)->enableUnlessUserOptedOut($user, $org->id);

        $this->assertFalse($settings->enabled);
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

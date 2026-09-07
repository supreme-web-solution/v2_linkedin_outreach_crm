<?php

namespace App\V2\Ai\Services;

use App\Models\AiEmployeeSetting;
use App\Models\User;
use App\V2\Ai\Enums\AiAutonomyLevel;
use App\V2\Ai\Enums\AiToolPermission;

class AiEmployeeSettingsService
{
    public function for(User $user, int $organizationId): AiEmployeeSetting
    {
        $userOverride = AiEmployeeSetting::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->first();

        if ($userOverride) {
            return $userOverride;
        }

        return AiEmployeeSetting::query()->firstOrCreate(
            [
                'organization_id' => $organizationId,
                'user_id' => null,
            ],
            [
                'enabled' => (bool) config('socifusion_ai.enabled', true),
                'kill_switch' => (bool) config('socifusion_ai.kill_switch', false),
                'autonomy_level' => (int) config('socifusion_ai.default_autonomy_level', 2),
                'employee_name' => (string) config('socifusion_ai.employee_name', 'Alex'),
                'allowed_execute_tools' => config('socifusion_ai.default_allowed_execute_tools', []),
            ]
        );
    }

    public function isBlocked(AiEmployeeSetting $settings): bool
    {
        if ((bool) config('socifusion_ai.kill_switch', false)) {
            return true;
        }

        return ! $settings->enabled || $settings->kill_switch;
    }

    public function autonomy(AiEmployeeSetting $settings): AiAutonomyLevel
    {
        return AiAutonomyLevel::tryFrom((int) $settings->autonomy_level) ?? AiAutonomyLevel::Assisted;
    }

    public function mayRun(AiEmployeeSetting $settings, AiToolPermission $permission, string $toolName): bool
    {
        if ($this->isBlocked($settings)) {
            return false;
        }

        $level = $this->autonomy($settings);

        return match ($permission) {
            AiToolPermission::Read => true,
            AiToolPermission::Prepare => $level->value >= AiAutonomyLevel::Copilot->value,
            AiToolPermission::Execute => $this->mayExecute($settings, $level, $toolName),
        };
    }

    private function mayExecute(AiEmployeeSetting $settings, AiAutonomyLevel $level, string $toolName): bool
    {
        if ($level === AiAutonomyLevel::Copilot) {
            return false;
        }

        if ($level === AiAutonomyLevel::Assisted) {
            return false; // requires approval path, not direct execute
        }

        $allowlist = $settings->allowed_execute_tools
            ?? config('socifusion_ai.default_allowed_execute_tools', []);

        if ($level === AiAutonomyLevel::Autopilot) {
            return in_array($toolName, $allowlist, true);
        }

        // Autonomous: allowlisted tools auto; others still need approval elsewhere
        return in_array($toolName, $allowlist, true);
    }
}

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
        // Destructive tools never auto-execute — always Review & Launch.
        if ($this->isDestructiveTool($toolName)) {
            return false;
        }

        if ($level === AiAutonomyLevel::Copilot) {
            return false;
        }

        if ($level === AiAutonomyLevel::Assisted) {
            return false; // requires approval path, not direct execute
        }

        $allowlist = $settings->allowed_execute_tools;
        if (! is_array($allowlist) || $allowlist === []) {
            $allowlist = $this->defaultExecuteTools();
        }

        $allowlist = $this->withoutDestructiveTools($allowlist);

        if ($level === AiAutonomyLevel::Autopilot) {
            return in_array($toolName, $allowlist, true);
        }

        // Autonomous: allowlisted tools auto; others still need approval elsewhere
        return in_array($toolName, $allowlist, true);
    }

    public function isDestructiveTool(string $toolName): bool
    {
        return $toolName === 'delete_campaign' || str_starts_with($toolName, 'delete_');
    }

    /**
     * @param  list<string>  $tools
     * @return list<string>
     */
    public function withoutDestructiveTools(array $tools): array
    {
        return array_values(array_filter(
            $tools,
            fn (string $tool) => ! $this->isDestructiveTool($tool),
        ));
    }

    /**
     * @param  array{enabled?:bool, employee_name?:string, autonomy_level?:int}  $data
     */
    public function updateForUser(
        User $user,
        int $organizationId,
        array $data,
        bool $allowAutonomous = false,
    ): AiEmployeeSetting {
        $current = $this->for($user, $organizationId);

        $row = AiEmployeeSetting::query()->firstOrNew([
            'organization_id' => $organizationId,
            'user_id' => $user->id,
        ]);

        if (! $row->exists) {
            $row->fill([
                'enabled' => $current->enabled,
                'kill_switch' => false,
                'autonomy_level' => $current->autonomy_level,
                'employee_name' => $current->employee_name,
                'allowed_execute_tools' => $current->allowed_execute_tools,
            ]);
        }

        if (array_key_exists('enabled', $data)) {
            $row->enabled = (bool) $data['enabled'];
        }

        if (array_key_exists('employee_name', $data)) {
            $name = trim((string) $data['employee_name']);
            if ($name !== '') {
                $row->employee_name = $name;
            }
        }

        if (array_key_exists('autonomy_level', $data)) {
            $level = (int) $data['autonomy_level'];
            if (! in_array($level, [
                AiAutonomyLevel::Copilot->value,
                AiAutonomyLevel::Assisted->value,
                AiAutonomyLevel::Autopilot->value,
                AiAutonomyLevel::Autonomous->value,
            ], true)) {
                throw new \InvalidArgumentException('Invalid autonomy level.');
            }

            if ($level === AiAutonomyLevel::Autonomous->value && ! $allowAutonomous) {
                throw new \InvalidArgumentException('Autonomous mode is admin-only.');
            }

            $row->autonomy_level = $level;
        }

        if (array_key_exists('allowed_execute_tools', $data)) {
            $row->allowed_execute_tools = $this->withoutDestructiveTools(array_values(array_unique(array_filter(
                (array) $data['allowed_execute_tools'],
                fn ($tool) => is_string($tool) && $tool !== '',
            ))));
        }

        if (array_key_exists('sender_display_name', $data)) {
            $meta = is_array($row->meta) ? $row->meta : (is_array($current->meta) ? $current->meta : []);
            $sender = trim((string) $data['sender_display_name']);
            if ($sender === '') {
                unset($meta['sender_display_name']);
            } else {
                $meta['sender_display_name'] = $sender;
            }
            $row->meta = $meta;
        }

        $row->save();

        return $row->fresh();
    }

    /**
     * @return list<string>
     */
    public function defaultExecuteTools(): array
    {
        return array_values(array_unique(array_filter(
            (array) config('socifusion_ai.default_allowed_execute_tools', []),
            fn ($tool) => is_string($tool) && $tool !== '',
        )));
    }

    public function configureWorkspaceForGoal(User $user, int $organizationId, string $goalKey): AiEmployeeSetting
    {
        return $this->updateForUser($user, $organizationId, [
            'autonomy_level' => AiAutonomyLevel::Autopilot->value,
            'allowed_execute_tools' => $this->defaultExecuteTools(),
        ], allowAutonomous: true);
    }

    public function enableUnlessUserOptedOut(User $user, int $organizationId): AiEmployeeSetting
    {
        $userRow = AiEmployeeSetting::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->first();

        if ($userRow && ! $userRow->enabled) {
            return $userRow;
        }

        return $this->updateForUser($user, $organizationId, ['enabled' => true]);
    }
}

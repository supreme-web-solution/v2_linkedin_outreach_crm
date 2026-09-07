<?php

namespace App\V2\Ai;

use App\Models\AiConversation;
use App\Models\AiEmployeeSetting;
use App\Models\User;
use App\V2\Ai\Enums\AiAutonomyLevel;
use App\V2\Ai\Services\AiEmployeeSettingsService;

final class AgentContext
{
    public function __construct(
        public readonly User $user,
        public readonly int $organizationId,
        public readonly AiEmployeeSetting $settings,
        public readonly AiConversation $conversation,
        public readonly string $channel = 'web',
    ) {}

    public function activeSettings(): AiEmployeeSetting
    {
        return app(AiEmployeeSettingsService::class)->for($this->user, $this->organizationId);
    }

    public function employeeName(): string
    {
        $settings = $this->activeSettings();

        return $settings->employee_name ?: (string) config('socifusion_ai.employee_name', 'Alex');
    }

    public function autonomy(): AiAutonomyLevel
    {
        $settings = $this->activeSettings();

        return AiAutonomyLevel::tryFrom((int) $settings->autonomy_level) ?? AiAutonomyLevel::Assisted;
    }
}

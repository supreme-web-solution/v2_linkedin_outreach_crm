<?php

namespace App\V2\Ai;

use App\Models\AiConversation;
use App\Models\AiEmployeeSetting;
use App\Models\User;
use App\V2\Ai\Enums\AiAutonomyLevel;

final class AgentContext
{
    public function __construct(
        public readonly User $user,
        public readonly int $organizationId,
        public readonly AiEmployeeSetting $settings,
        public readonly AiConversation $conversation,
        public readonly string $channel = 'web',
    ) {}

    public function employeeName(): string
    {
        return $this->settings->employee_name ?: (string) config('socifusion_ai.employee_name', 'Alex');
    }

    public function autonomy(): AiAutonomyLevel
    {
        return AiAutonomyLevel::tryFrom((int) $this->settings->autonomy_level) ?? AiAutonomyLevel::Assisted;
    }
}

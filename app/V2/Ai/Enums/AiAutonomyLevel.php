<?php

namespace App\V2\Ai\Enums;

enum AiAutonomyLevel: int
{
    case Copilot = 1;
    case Assisted = 2;
    case Autopilot = 3;
    case Autonomous = 4;

    public function label(): string
    {
        return match ($this) {
            self::Copilot => 'Copilot',
            self::Assisted => 'Assisted',
            self::Autopilot => 'Autopilot',
            self::Autonomous => 'Autonomous',
        };
    }
}

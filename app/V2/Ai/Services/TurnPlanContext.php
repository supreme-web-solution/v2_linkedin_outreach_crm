<?php

namespace App\V2\Ai\Services;

class TurnPlanContext
{
    /** @var array<string,mixed>|null */
    private static ?array $plan = null;

    /**
     * @param  array<string,mixed>  $plan
     */
    public function set(array $plan): void
    {
        self::$plan = $plan;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function get(): ?array
    {
        return self::$plan;
    }

    public function clear(): void
    {
        self::$plan = null;
    }
}

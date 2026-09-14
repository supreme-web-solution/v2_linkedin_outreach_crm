<?php

namespace App\Ai\Agents;

use App\V2\Ai\Support\SemanticTurnPlanContract;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Meaning-only turn interpreter for Soci Command Center.
 * Returns a governed semantic plan — never tools or side effects.
 */
class SemanticTurnPlanAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return SemanticTurnPlanContract::systemPrompt();
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return SemanticTurnPlanContract::structuredSchema($schema);
    }

    public function timeout(): int
    {
        return 90;
    }
}

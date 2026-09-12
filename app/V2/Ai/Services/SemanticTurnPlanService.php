<?php

namespace App\V2\Ai\Services;

use App\Models\User;
use App\V2\Ai\Support\SemanticTurnPlanContract;
use App\V2\Services\OpenAIContentService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Governed LLM semantic interpretation — meaning only, no tools or DB access.
 */
class SemanticTurnPlanService
{
    public function __construct(
        private readonly OpenAIContentService $openai,
        private readonly SemanticTurnPlanNormalizer $normalizer,
        private readonly AiActionLogService $actionLogs,
    ) {}

    /**
     * @return array{semantic:array<string,mixed>,enforcement:array<string,mixed>}|null
     */
    public function interpret(User $user, int $organizationId, string $message): ?array
    {
        if (! config('socifusion_ai.semantic_turn_planner', true)) {
            Log::info('[Soci] Semantic planner disabled — regex fallback', [
                'user_id' => $user->id,
            ]);

            return null;
        }

        if (! $this->openai->isConfigured()) {
            Log::warning('[Soci] Semantic planner unavailable — no LLM provider configured', [
                'user_id' => $user->id,
            ]);

            return null;
        }

        $trimmed = trim($message);
        if ($trimmed === '') {
            return null;
        }

        $started = hrtime(true);

        try {
            $raw = $this->openai->generateAgentJson(
                SemanticTurnPlanContract::systemPrompt(),
                json_encode(['user_message' => $trimmed], JSON_THROW_ON_ERROR),
                700,
                true,
            );

            if ($raw === []) {
                Log::warning('[Soci] Semantic planner returned empty JSON — regex fallback', [
                    'user_id' => $user->id,
                ]);

                return null;
            }

            $semantic = $this->normalizer->sanitizeSemantic($raw);
            $enforcement = $this->normalizer->toEnforcementPlan($semantic, $trimmed);

            $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);
            $this->actionLogs->log(
                user: $user,
                organizationId: $organizationId,
                tool: 'semantic_turn_planner',
                permission: \App\V2\Ai\Enums\AiToolPermission::Read,
                status: 'success',
                conversation: null,
                input: ['message' => $trimmed],
                output: ['semantic' => $semantic, 'enforcement' => [
                    'goal' => $enforcement['goal'] ?? null,
                    'required_outcome' => $enforcement['required_outcome'] ?? null,
                    'side_effect_budget' => $enforcement['side_effect_budget'] ?? null,
                ]],
                error: null,
                durationMs: $durationMs,
            );

            return [
                'semantic' => $semantic,
                'enforcement' => $enforcement,
            ];
        } catch (Throwable $e) {
            Log::warning('[Soci] Semantic turn planner failed — regex fallback', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}

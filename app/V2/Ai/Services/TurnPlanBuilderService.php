<?php

namespace App\V2\Ai\Services;

use App\Models\AiEmployeeSetting;
use App\Models\User;

class TurnPlanBuilderService
{
    public function __construct(
        private readonly SemanticTurnPlanService $semanticPlanner,
        private readonly IntentGoalResolverService $regexFallback,
        private readonly FallbackTurnPlanSafetyService $fallbackSafety,
        private readonly AiActionLogService $actionLogs,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function build(User $user, int $organizationId, string $message, AiEmployeeSetting $settings): array
    {
        $interpreted = $this->semanticPlanner->interpret($user, $organizationId, $message);

        if ($interpreted !== null) {
            $plan = $interpreted['enforcement'];
            $plan['semantic_source'] = 'llm';
            $plan['planning_degraded'] = false;
        } else {
            $resolved = $this->regexFallback->resolve($message);
            $reason = config('socifusion_ai.semantic_turn_planner', true)
                ? 'llm_unavailable_or_failed'
                : 'semantic_planner_disabled';

            $plan = $this->fallbackSafety->apply([
                'goal' => $resolved['goal'],
                'objective' => is_array($resolved['objective'] ?? null) ? $resolved['objective'] : [],
                'desired_operation' => (string) ($resolved['desired_operation'] ?? ''),
                'required_outcome' => $resolved['required_outcome'],
                'side_effect_budget' => $resolved['side_effect_budget'],
                'constraints' => is_array($resolved['constraints'] ?? null) ? $resolved['constraints'] : [],
                'execution_preferences' => [
                    'approval_required' => in_array((string) ($resolved['required_outcome'] ?? ''), ['send_now', 'delete_now', 'execute_now'], true),
                    'source' => ($resolved['constraints']['new_only'] ?? false) ? 'new_only' : 'reuse_first',
                ],
                'measurable_expectations' => [
                    'target_count' => $resolved['constraints']['target_count'] ?? null,
                ],
                'built_at' => now()->toIso8601String(),
            ], $reason);

            $this->actionLogs->log(
                user: $user,
                organizationId: $organizationId,
                tool: 'semantic_turn_planner',
                permission: \App\V2\Ai\Enums\AiToolPermission::Read,
                status: 'fallback',
                conversation: null,
                input: ['message' => $message, 'reason' => $reason],
                output: [
                    'required_outcome' => $plan['required_outcome'] ?? null,
                    'side_effect_budget' => $plan['side_effect_budget'] ?? null,
                    'fallback_safety_cap' => $plan['fallback_safety_cap'] ?? false,
                ],
            );
        }

        /** @var WorkspaceContextService $workspaceContext */
        $workspaceContext = app(WorkspaceContextService::class);
        $workspace = $workspaceContext->workspaceGoalProfile($settings);

        $plan['constraints'] = array_merge(
            is_array($plan['constraints'] ?? null) ? $plan['constraints'] : [],
            [
                'organization_id' => $organizationId,
                'user_id' => $user->id,
                'preferred_channels' => $workspace['preferred_channels'] ?? [],
                'send_policy' => $workspace['send_policy'] ?? 'approval_required',
                'new_vs_existing_preference' => $workspace['new_vs_existing_preference'] ?? 'reuse_first',
            ],
        );

        if (! isset($plan['execution_preferences'])) {
            $plan['execution_preferences'] = [
                'approval_required' => in_array((string) ($plan['required_outcome'] ?? ''), ['send_now', 'delete_now', 'execute_now'], true),
                'source' => $workspace['new_vs_existing_preference'] ?? 'reuse_first',
            ];
        }

        $plan['workspace_goal_profile'] = $workspace;

        $plan['state_evaluation'] = app(TurnPlanStateEvaluationService::class)
            ->evaluate($user, $organizationId, $plan);

        $requested = (int) ($plan['state_evaluation']['requested_quantity'] ?? 0);
        $outcome = (string) ($plan['required_outcome'] ?? '');
        $plan['workflow_eligible'] = ! ($plan['state_evaluation']['skipped'] ?? true)
            && $requested > 0
            && in_array($outcome, ['find_only', 'send_now', 'setup_only'], true);

        return $plan;
    }
}

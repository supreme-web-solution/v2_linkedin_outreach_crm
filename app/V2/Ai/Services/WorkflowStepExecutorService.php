<?php

namespace App\V2\Ai\Services;

use App\Models\User;
use App\V2\Ai\Support\WorkflowStepTypes;

/**
 * Executes a single workflow step and returns structured observation.
 * Does not decide what step comes next.
 */
class WorkflowStepExecutorService
{
    public function __construct(
        private readonly WorkflowDiscoveryStepHandler $discoveryHandler,
        private readonly WorkflowPrepareOutreachStepHandler $prepareHandler,
        private readonly WorkflowAwaitingApprovalStepHandler $approvalHandler,
        private readonly TurnPlanStateEvaluationService $stateEvaluation,
    ) {}

    /**
     * @param  array<string, mixed>  $stepDef
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    public function execute(
        User $user,
        int $organizationId,
        array $stepDef,
        array $plan,
        ?array $baselineState = null,
    ): array {
        $stepType = (string) ($stepDef['step_type'] ?? $stepDef['tool_name'] ?? '');
        $workflowRunId = (int) ($stepDef['workflow_run_id'] ?? 0);
        $conversationId = isset($stepDef['conversation_id']) ? (int) $stepDef['conversation_id'] : null;

        $result = match ($stepType) {
            WorkflowStepTypes::EVALUATE_EXISTING => ['step_type' => $stepType, 'observed' => true],
            WorkflowStepTypes::DISCOVER => $this->discoveryHandler->execute($user, $plan, $stepDef['arguments'] ?? []),
            WorkflowStepTypes::PREPARE_OUTREACH => $this->prepareHandler->execute(
                $user,
                $organizationId,
                $plan,
                $stepDef['arguments'] ?? [],
                $workflowRunId,
                $conversationId,
            ),
            WorkflowStepTypes::AWAITING_APPROVAL => $this->approvalHandler->execute(
                $workflowRunId,
                $stepDef['arguments'] ?? [],
            ),
            WorkflowStepTypes::VERIFY => ['step_type' => $stepType, 'verified' => true],
            default => ['step_type' => $stepType, 'skipped' => true],
        };

        if (($result['waiting'] ?? false) === true) {
            $result['state_after'] = is_array($plan['state_evaluation'] ?? null)
                ? $plan['state_evaluation']
                : $this->stateEvaluation->evaluate($user, $organizationId, $plan);

            return $result;
        }

        $stateAfter = $this->stateEvaluation->evaluate($user, $organizationId, $plan);
        $result['state_after'] = $stateAfter;

        if ($baselineState !== null) {
            $result['state_delta'] = $this->stateEvaluation->deltaFromBaseline($stateAfter, $baselineState, $plan);
        }

        return $result;
    }
}

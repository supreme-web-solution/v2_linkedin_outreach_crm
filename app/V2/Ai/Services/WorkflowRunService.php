<?php

namespace App\V2\Ai\Services;

use App\Models\AiConversation;
use App\Models\AiWorkflowRun;
use App\Models\AiWorkflowStep;
use App\Models\User;

class WorkflowRunService
{
    /**
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $approvalScope
     */
    public function createPlannedRun(
        User $user,
        int $organizationId,
        ?AiConversation $conversation,
        array $plan,
        array $approvalScope = [],
    ): AiWorkflowRun {
        $scopeHash = $this->scopeHash($approvalScope);

        return AiWorkflowRun::query()->create([
            'organization_id' => $organizationId,
            'user_id' => $user->id,
            'conversation_id' => $conversation?->id,
            'goal' => (string) ($plan['goal'] ?? ''),
            'status' => 'planned',
            'plan' => $plan,
            'approval_status' => 'pending',
            'approval_scope' => $approvalScope,
            'scope_hash' => $scopeHash,
        ]);
    }

    /**
     * @param  list<array<string,mixed>>  $steps
     */
    public function addSteps(AiWorkflowRun $run, array $steps): void
    {
        foreach ($steps as $i => $step) {
            AiWorkflowStep::query()->create([
                'workflow_run_id' => $run->id,
                'step_key' => (string) ($step['step_key'] ?? ('step_'.$i)),
                'sequence' => (int) ($step['sequence'] ?? ($i + 1)),
                'tool_name' => (string) ($step['tool_name'] ?? ''),
                'arguments' => is_array($step['arguments'] ?? null) ? $step['arguments'] : [],
                'status' => 'pending',
                'depends_on' => is_array($step['depends_on'] ?? null) ? $step['depends_on'] : [],
                'approval_required' => (bool) ($step['approval_required'] ?? false),
                'approval_status' => (bool) ($step['approval_required'] ?? false) ? 'pending' : 'not_required',
            ]);
        }
    }

    /**
     * @param  array<string,mixed>  $approvedScope
     */
    public function requiresReapproval(AiWorkflowRun $run, array $approvedScope): bool
    {
        return $run->scope_hash !== $this->scopeHash($approvedScope);
    }

    public function markApproved(AiWorkflowRun $run): void
    {
        $run->update([
            'approval_status' => 'approved',
            'status' => $run->status === 'planned' ? 'approved' : $run->status,
        ]);
    }

    public function markRejected(AiWorkflowRun $run): void
    {
        $run->update([
            'approval_status' => 'rejected',
            'status' => in_array($run->status, ['planned', 'approved'], true) ? 'cancelled' : $run->status,
        ]);
    }

    /**
     * @param  array<string,mixed>  $scope
     */
    public function scopeHash(array $scope): string
    {
        ksort($scope);

        return hash('sha256', json_encode($scope, JSON_THROW_ON_ERROR));
    }

    public function markRunning(AiWorkflowRun $run, ?string $step = null): void
    {
        $run->update([
            'status' => 'running',
            'started_at' => $run->started_at ?? now(),
            'current_step' => $step ?? $run->current_step,
        ]);
    }

    /**
     * @param array<string,mixed>|null $result
     */
    public function markCompleted(AiWorkflowRun $run, ?array $result = null): void
    {
        $run->update([
            'status' => 'completed',
            'completed_at' => now(),
            'result' => $result ?? $run->result,
        ]);
    }

    public function markFailed(AiWorkflowRun $run, string $error): void
    {
        $run->update([
            'status' => 'failed',
            'failed_at' => now(),
            'error' => $error,
        ]);
    }

    public function markBlocked(AiWorkflowRun $run, string $reason, ?array $result = null): void
    {
        $run->update([
            'status' => 'blocked',
            'error' => $reason,
            'result' => $result ?? $run->result,
        ]);
    }

    public function markWaiting(AiWorkflowRun $run, string $stepKey): void
    {
        $run->update([
            'status' => 'waiting',
            'current_step' => $stepKey,
        ]);
    }

    public function markStepRunning(AiWorkflowStep $step): void
    {
        $step->update([
            'status' => 'running',
            'started_at' => $step->started_at ?? now(),
        ]);
        $this->markRunning($step->workflowRun, $step->step_key);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function markStepCompleted(AiWorkflowStep $step, array $result): void
    {
        $step->update([
            'status' => 'completed',
            'completed_at' => now(),
            'result' => $result,
            'error' => null,
        ]);
    }

    public function markStepWaiting(AiWorkflowStep $step, ?array $result = null): void
    {
        $step->update([
            'status' => 'waiting',
            'result' => $result ?? $step->result,
            'error' => null,
        ]);
    }

    public function markStepFailed(AiWorkflowStep $step, string $error, ?array $result = null): void
    {
        $step->update([
            'status' => 'failed',
            'completed_at' => now(),
            'error' => $error,
            'result' => $result,
        ]);
    }

    public function incrementStepRetry(AiWorkflowStep $step): void
    {
        $step->update(['retry_count' => (int) $step->retry_count + 1]);
    }
}

<?php

namespace App\V2\Ai\Services;

use App\Models\AiWorkflowRun;

/**
 * Prevents the LLM agent from duplicating workflow-owned orchestration steps.
 */
class WorkflowOrchestrationGuardService
{
    /** @var list<string> */
    private const TERMINAL_STATUSES = ['completed', 'failed', 'blocked', 'cancelled'];

    /**
     * @param  array<string, mixed>|null  $turnPlan
     * @return array<string, mixed>|null Block payload when tool should not run
     */
    public function blockTool(?array $turnPlan, string $tool): ?array
    {
        $runId = (int) ($turnPlan['workflow_run_id'] ?? 0);
        if ($runId <= 0) {
            return null;
        }

        $run = AiWorkflowRun::query()->find($runId);
        if (! $run || in_array((string) $run->status, self::TERMINAL_STATUSES, true)) {
            return null;
        }

        $meta = is_array($run->meta) ? $run->meta : [];
        $prepared = (bool) ($meta['prepared'] ?? false);

        if ($tool === 'discover_prospects') {
            return [
                'blocked' => true,
                'workflow_owned' => true,
                'workflow_run_id' => $runId,
                'instruction' => 'Discovery is handled by workflow run #'.$runId.'. Do NOT call discover_prospects — report workflow progress or wait for continuation.',
            ];
        }

        if ($tool === 'draft_campaign_plan' && ! $prepared) {
            return [
                'blocked' => true,
                'workflow_owned' => true,
                'workflow_run_id' => $runId,
                'instruction' => 'Campaign preparation is handled by workflow run #'.$runId.'. Do NOT call draft_campaign_plan — the workflow will stage the plan.',
            ];
        }

        return null;
    }
}

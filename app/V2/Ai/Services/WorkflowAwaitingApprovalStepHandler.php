<?php

namespace App\V2\Ai\Services;

use App\Models\AiActionApproval;
use App\Models\AiWorkflowRun;

/**
 * Pauses the workflow until the user LAUNCHes the staged campaign plan.
 */
class WorkflowAwaitingApprovalStepHandler
{
    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function execute(int $workflowRunId, array $arguments): array
    {
        $approvalId = (int) ($arguments['approval_id'] ?? 0);
        if ($approvalId <= 0) {
            throw new \RuntimeException('Workflow awaiting approval step missing approval_id.');
        }

        $approval = AiActionApproval::query()->find($approvalId);
        if (! $approval) {
            throw new \RuntimeException("Approval #{$approvalId} not found for workflow run {$workflowRunId}.");
        }

        if ((int) ($approval->workflow_run_id ?? 0) !== $workflowRunId) {
            throw new \RuntimeException('Approval does not belong to this workflow run.');
        }

        if ($approval->status === 'executed') {
            return [
                'step_type' => 'awaiting_approval',
                'already_executed' => true,
                'approval_id' => $approvalId,
                'outreach_campaign_id' => (int) data_get($approval->result, 'outreach_campaign_id'),
                'waiting' => false,
            ];
        }

        if ($approval->status === 'rejected') {
            throw new \RuntimeException('Campaign plan was rejected — workflow cannot continue.');
        }

        $run = AiWorkflowRun::query()->find($workflowRunId);
        if ($run) {
            app(WorkflowRunService::class)->markWaiting($run, 'awaiting_approval');
        }

        return [
            'step_type' => 'awaiting_approval',
            'waiting' => true,
            'approval_id' => $approvalId,
            'approval_status' => $approval->status,
            'instruction' => 'Campaign staged for Review & Launch. Send LAUNCH #'.$approvalId.' when ready.',
        ];
    }
}

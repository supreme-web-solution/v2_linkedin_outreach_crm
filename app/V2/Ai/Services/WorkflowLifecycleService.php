<?php

namespace App\V2\Ai\Services;

use App\Models\AiActionApproval;
use App\Models\AiWorkflowRun;
use App\Models\AiWorkflowStep;
use App\Models\User;
use App\Models\V2OutreachCampaign;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Cancels durable workflow runs with campaigns / approvals so Soci never
 * reports dead "send_now" backgrounds after delete or reject.
 */
class WorkflowLifecycleService
{
    /** @var list<string> */
    private const ACTIVE_STATUSES = ['planned', 'approved', 'running', 'waiting', 'blocked'];

    public function cancelRun(AiWorkflowRun $run, string $reason = 'cancelled', ?User $decider = null): AiWorkflowRun
    {
        $run->refresh();
        if (! in_array((string) $run->status, self::ACTIVE_STATUSES, true)) {
            return $run;
        }

        AiWorkflowStep::query()
            ->where('workflow_run_id', $run->id)
            ->whereIn('status', ['pending', 'running', 'waiting'])
            ->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error' => $reason,
            ]);

        // Update approvals directly — do not call ActionApprovalService::reject
        // (that path also cancels the workflow and would recurse).
        AiActionApproval::query()
            ->where('workflow_run_id', $run->id)
            ->where('status', 'pending')
            ->update([
                'status' => 'rejected',
                'decided_at' => Carbon::now(),
                'decided_by' => $decider?->id ?? $run->user_id,
            ]);

        $meta = is_array($run->meta) ? $run->meta : [];
        $meta['cancelled_reason'] = $reason;
        $meta['cancelled_at'] = now()->toIso8601String();
        $meta['awaiting_approval'] = false;

        $run->update([
            'status' => 'cancelled',
            'approval_status' => 'rejected',
            'completed_at' => $run->completed_at ?? now(),
            'error' => $reason,
            'meta' => $meta,
        ]);

        Log::info('[WorkflowLifecycle] Cancelled workflow run', [
            'workflow_run_id' => $run->id,
            'reason' => $reason,
            'user_id' => $run->user_id,
            'organization_id' => $run->organization_id,
        ]);

        return $run->fresh() ?? $run;
    }

    public function cancelForApproval(AiActionApproval $approval, ?User $decider = null): void
    {
        $run = $approval->workflowRun;
        if (! $run) {
            $runId = (int) ($approval->workflow_run_id ?? 0);
            if ($runId > 0) {
                $run = AiWorkflowRun::query()->find($runId);
            }
        }

        if ($run) {
            $this->cancelRun($run, 'approval_rejected', $decider ?? $approval->user);
        }
    }

    /**
     * Cancel workflows that launched or staged against a deleted outreach campaign.
     */
    public function cancelForOutreachCampaign(User $user, int $organizationId, int $campaignId): int
    {
        $cancelled = 0;

        $runs = AiWorkflowRun::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        foreach ($runs as $run) {
            $meta = is_array($run->meta) ? $run->meta : [];
            $result = is_array($run->result) ? $run->result : [];
            $linked = (int) ($meta['outreach_campaign_id'] ?? $result['outreach_campaign_id'] ?? 0);
            if ($linked === $campaignId) {
                $this->cancelRun($run, 'outreach_campaign_deleted', $user);
                $cancelled++;

                continue;
            }

            $approvalIds = is_array($meta['approval_ids'] ?? null) ? $meta['approval_ids'] : [];
            if ($approvalIds === [] && (int) ($meta['approval_id'] ?? 0) > 0) {
                $approvalIds = [(int) $meta['approval_id']];
            }

            foreach ($approvalIds as $approvalId) {
                $approval = AiActionApproval::query()->find((int) $approvalId);
                if (! $approval) {
                    continue;
                }
                $payloadCampaign = (int) (is_array($approval->payload) ? ($approval->payload['outreach_campaign_id'] ?? 0) : 0);
                $resultCampaign = (int) (is_array($approval->result) ? ($approval->result['outreach_campaign_id'] ?? 0) : 0);
                if ($payloadCampaign === $campaignId || $resultCampaign === $campaignId) {
                    $this->cancelRun($run, 'outreach_campaign_deleted', $user);
                    $cancelled++;
                    break;
                }
            }
        }

        return $cancelled;
    }

    /**
     * When the user has no outreach campaigns left, kill orphaned staging workflows
     * that were waiting for LAUNCH (the #6/#7/#10 class of stuck runs).
     * Does NOT cancel in-flight discovery/running finds.
     */
    public function cancelOrphanedStagingWhenNoCampaigns(User $user, int $organizationId): int
    {
        $remaining = V2OutreachCampaign::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->count();

        if ($remaining > 0) {
            return 0;
        }

        $runs = AiWorkflowRun::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->where('status', 'waiting')
            ->get();

        $cancelled = 0;
        foreach ($runs as $run) {
            $meta = is_array($run->meta) ? $run->meta : [];
            if (! empty($meta['executed'])) {
                continue;
            }
            // Parked on Review & Launch with no campaign left to attach to.
            if (! empty($meta['awaiting_approval']) || ! empty($meta['prepared']) || ! empty($meta['approval_id'])) {
                $this->cancelRun($run, 'all_campaigns_deleted', $user);
                $cancelled++;
            }
        }

        return $cancelled;
    }

    /**
     * Cancel every non-terminal staging/discovery run for a clean slate.
     */
    public function cancelAllActiveStaging(User $user, int $organizationId, string $reason = 'workspace_reset'): int
    {
        $runs = AiWorkflowRun::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->get();

        $cancelled = 0;
        foreach ($runs as $run) {
            $meta = is_array($run->meta) ? $run->meta : [];
            if (! empty($meta['executed'])) {
                continue;
            }
            $this->cancelRun($run, $reason, $user);
            $cancelled++;
        }

        return $cancelled;
    }
}

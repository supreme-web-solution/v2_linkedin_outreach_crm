<?php

namespace App\V2\Ai\Services;

use App\Models\AiWorkflowRun;
use App\Models\User;
use App\Models\V2Campaign;
use App\Models\V2OutreachCampaign;
use App\V2\Outreach\OutreachExecutionMetricsService;

class PostExecutionVerifierService
{
    /**
     * @return array{ok:bool,warnings:list<string>,checks:array<string,mixed>}
     */
    public function verify(User $user, int $organizationId, array $plan, array $before, array $after): array
    {
        $warnings = [];
        $outcome = (string) ($plan['required_outcome'] ?? '');
        $expected = (int) ($plan['measurable_expectations']['target_count'] ?? 0);
        $stateEval = is_array($plan['state_evaluation'] ?? null) ? $plan['state_evaluation'] : [];
        $channelEligible = isset($stateEval['channel_eligible_count']) ? (int) $stateEval['channel_eligible_count'] : null;
        $actualCampaignDelta = max(0, (int) (($after['outreach_campaigns'] ?? 0) - ($before['outreach_campaigns'] ?? 0)));
        $actualExternalDelta = max(0, (int) (($after['external_activity_count'] ?? 0) - ($before['external_activity_count'] ?? 0)));
        $executionMetrics = is_array($after['execution_metrics'] ?? null) ? $after['execution_metrics'] : [];

        if ($outcome === 'find_only') {
            if (($after['outreach_campaigns'] ?? 0) > ($before['outreach_campaigns'] ?? 0)
                || ($after['linkedin_campaigns'] ?? 0) > ($before['linkedin_campaigns'] ?? 0)) {
                $warnings[] = 'Discovery-only request created campaign records unexpectedly.';
            }
        }

        if ($outcome === 'setup_only') {
            if (($after['external_activity_count'] ?? 0) > ($before['external_activity_count'] ?? 0)) {
                $warnings[] = 'Setup-only request triggered external send activity.';
            }
        }
        if ($expected > 0 && $outcome === 'send_now' && $actualExternalDelta === 0 && ! $this->workflowStillOrchestrating($plan)) {
            $warnings[] = 'Requested outreach execution completed with zero recorded external send actions.';
        }
        if ($channelEligible !== null && $expected > 0 && $channelEligible < $expected) {
            $asyncDiscovery = $this->workflowStillOrchestrating($plan)
                || ($outcome === 'find_only' && (int) ($plan['workflow_run_id'] ?? 0) > 0);

            if (! $asyncDiscovery) {
                $warnings[] = "Only {$channelEligible} prospects are channel-eligible; requested {$expected}.";
            }
        }
        if (($executionMetrics['attempted'] ?? 0) > 0) {
            $successful = (int) ($executionMetrics['successful'] ?? 0);
            $failed = (int) ($executionMetrics['failed'] ?? 0);
            if ($successful + $failed !== (int) $executionMetrics['attempted']) {
                $warnings[] = 'Execution metrics inconsistent: attempted does not equal successful + failed.';
            }
            if ($expected > 0 && $successful < $expected && ($stateEval['channel'] ?? null) !== null) {
                $warnings[] = "Reported {$successful} successful sends, not {$expected} as requested.";
            }
        }
        if ($outcome === 'delete_now' && $actualCampaignDelta === 0 && $actualExternalDelta > 0) {
            $warnings[] = 'Delete-intent turn generated external activity unexpectedly.';
        }

        return [
            'ok' => $warnings === [],
            'warnings' => $warnings,
            'checks' => [
                'outcome' => $outcome,
                'expected_target_count' => $expected,
                'state_evaluation' => $stateEval !== [] ? $stateEval : null,
                'channel_eligible_count' => $channelEligible,
                'execution_metrics' => $executionMetrics !== [] ? $executionMetrics : null,
                'actual_campaign_delta' => $actualCampaignDelta,
                'actual_external_activity_delta' => $actualExternalDelta,
                'before' => $before,
                'after' => $after,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function workflowStillOrchestrating(array $plan): bool
    {
        $workflowRunId = (int) ($plan['workflow_run_id'] ?? 0);
        if ($workflowRunId <= 0) {
            return false;
        }

        $run = AiWorkflowRun::query()->find($workflowRunId);
        if (! $run) {
            return false;
        }

        if (in_array((string) $run->status, ['completed', 'failed', 'blocked', 'cancelled'], true)) {
            return false;
        }

        return ! (bool) (is_array($run->meta) ? ($run->meta['executed'] ?? false) : false);
    }

    /**
     * @return array<string,int>
     */
    public function snapshot(User $user, int $organizationId): array
    {
        $externalActions = ['campaign_activated', 'inbox_reply_sent', 'meeting_booked', 'campaign_paused'];
        $rows = app(AiActivityLogService::class)->queryForUser(
            $user,
            $organizationId,
            null,
            null,
            null,
            null,
            300
        );

        return [
            'outreach_campaigns' => V2OutreachCampaign::query()
                ->where('user_id', $user->id)
                ->where('organization_id', $organizationId)
                ->count(),
            'linkedin_campaigns' => V2Campaign::query()
                ->where('user_id', $user->id)
                ->where('organization_id', $organizationId)
                ->count(),
            'external_activity_count' => $rows !== [] ? count(array_filter(
                $rows,
                fn (array $row) => in_array((string) ($row['action'] ?? ''), $externalActions, true)
            )) : 0,
            'execution_metrics' => app(OutreachExecutionMetricsService::class)->forUser($user, $organizationId),
        ];
    }
}

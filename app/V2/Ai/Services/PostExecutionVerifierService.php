<?php

namespace App\V2\Ai\Services;

use App\Models\AiWorkflowRun;
use App\Models\User;
use App\Models\V2Campaign;
use App\Models\V2OutreachCampaign;
use App\V2\Ai\Support\SingleRecipientTurnGuard;
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
        $singleRecipient = SingleRecipientTurnGuard::matches($plan);
        $plannedChannel = SingleRecipientTurnGuard::plannedChannel($plan);

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

        // Assisted cold one-shots stage Review & Launch — zero external sends on the planning turn is expected.
        if ($expected > 0
            && $outcome === 'send_now'
            && $actualExternalDelta === 0
            && ! $singleRecipient
            && ! $this->workflowStillOrchestrating($plan)
        ) {
            $warnings[] = 'Requested outreach execution completed with zero recorded external send actions.';
        }

        if ($channelEligible !== null && $expected > 0 && $channelEligible < $expected) {
            $asyncDiscovery = ! $singleRecipient && (
                $this->workflowStillOrchestrating($plan)
                || ($outcome === 'find_only' && (int) ($plan['workflow_run_id'] ?? 0) > 0)
            );

            if (! $asyncDiscovery && ! $singleRecipient) {
                $warnings[] = "Only {$channelEligible} prospects are channel-eligible; requested {$expected}.";
            }
        }

        // Send metrics apply only to send_now — never confuse discovery target_count with "sends".
        if ($outcome === 'send_now' && ! $singleRecipient && ($executionMetrics['attempted'] ?? 0) > 0) {
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

        $sideEffect = $this->singleRecipientSideEffects(
            $user,
            $organizationId,
            $plan,
            $before,
            $after,
            $singleRecipient,
            $plannedChannel,
            $actualCampaignDelta,
        );
        $warnings = array_merge($warnings, $sideEffect['warnings']);

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
                'single_recipient_turn' => $singleRecipient,
                'planned_channel' => $plannedChannel,
                'side_effect_checks' => $sideEffect['checks'],
                'before' => $before,
                'after' => $after,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{warnings:list<string>,checks:array<string,mixed>}
     */
    private function singleRecipientSideEffects(
        User $user,
        int $organizationId,
        array $plan,
        array $before,
        array $after,
        bool $singleRecipient,
        ?string $plannedChannel,
        int $actualCampaignDelta,
    ): array {
        $warnings = [];
        $checks = [
            'workflow_started' => false,
            'list_discovery_campaigns' => [],
            'channel_mismatches' => [],
        ];

        if (! $singleRecipient) {
            return ['warnings' => $warnings, 'checks' => $checks];
        }

        if ((int) ($plan['workflow_run_id'] ?? 0) > 0) {
            $checks['workflow_started'] = true;
            $warnings[] = 'Single-recipient cold outbound started a multi-step discovery workflow unexpectedly.';
        }

        $workflowDelta = max(0, (int) (($after['workflow_runs'] ?? 0) - ($before['workflow_runs'] ?? 0)));
        if ($workflowDelta > 0) {
            $checks['workflow_started'] = true;
            $warnings[] = 'Single-recipient turn created workflow run(s) — list discovery must not run for an explicit recipient.';
        }

        if ($actualCampaignDelta > 0) {
            $newest = V2OutreachCampaign::query()
                ->where('user_id', $user->id)
                ->where('organization_id', $organizationId)
                ->orderByDesc('id')
                ->limit(max(1, $actualCampaignDelta))
                ->get(['id', 'name', 'meta', 'node_model']);

            foreach ($newest as $campaign) {
                $meta = is_array($campaign->meta) ? $campaign->meta : [];
                $aiPlan = is_array($meta['ai_plan'] ?? null) ? $meta['ai_plan'] : [];
                $source = strtolower(trim((string) ($aiPlan['source'] ?? $meta['source'] ?? '')));
                $oneShot = ! empty($aiPlan['one_shot']) || ! empty($meta['one_shot']);
                $channel = strtolower(trim((string) (
                    $aiPlan['primary_channel']
                    ?? $meta['primary_channel']
                    ?? $this->inferChannelFromNodes(is_array($campaign->node_model) ? $campaign->node_model : [])
                    ?? ''
                )));

                $checks['list_discovery_campaigns'][] = [
                    'id' => $campaign->id,
                    'source' => $source,
                    'channel' => $channel,
                    'one_shot' => $oneShot,
                ];

                if (! $oneShot || ! in_array($source, ['cold_outbound', ''], true)) {
                    if (str_contains($source, 'discovery') || str_contains($source, 'workflow') || str_contains($source, 'parallel') || $source === '') {
                        $warnings[] = "Single-recipient request staged campaign #{$campaign->id} from list/discovery ({$source}) instead of cold outbound only.";
                    }
                }

                if ($plannedChannel && $channel !== '' && $plannedChannel !== $channel) {
                    $checks['channel_mismatches'][] = [
                        'planned' => $plannedChannel,
                        'created' => $channel,
                        'campaign_id' => $campaign->id,
                    ];
                    $warnings[] = "Planned channel {$plannedChannel} but campaign #{$campaign->id} was created on {$channel}.";
                }
            }
        }

        return ['warnings' => array_values(array_unique($warnings)), 'checks' => $checks];
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    private function inferChannelFromNodes(array $nodes): ?string
    {
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $channel = strtolower(trim((string) ($node['channel'] ?? '')));
            if ($channel !== '' && ($node['type'] ?? '') === 'action') {
                return $channel;
            }
        }

        return null;
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
     * @return array<string, int|array<string, mixed>>
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
            'workflow_runs' => AiWorkflowRun::query()
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

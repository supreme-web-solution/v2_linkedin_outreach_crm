<?php

namespace App\V2\Ai\Services;

use App\V2\Ai\Support\WorkflowStepTypes;

/**
 * Decides the next workflow step from turn plan + current state evaluation.
 * Does not execute tools — planning only.
 */
class WorkflowPlanBuilderService
{
    public const MAX_DISCOVERY_ATTEMPTS = 5;

    public function __construct(
        private readonly DiscoveryPlatformResolver $platformResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $stateEval
     * @param  array<string, mixed>  $runMeta
     * @return array<string, mixed>|null
     */
    public function nextStep(array $plan, array $stateEval, array $runMeta = []): ?array
    {
        if ($stateEval['skipped'] ?? true) {
            return null;
        }

        $requested = (int) ($stateEval['requested_quantity'] ?? 0);
        $remaining = $this->effectiveRemainingDiscovery($stateEval, $runMeta);
        $discoveryAttempts = (int) ($runMeta['discovery_attempts'] ?? 0);
        $requiresDiscovery = (bool) ($stateEval['requires_external_discovery'] ?? false);

        if ($this->isProspectQuotaMet($plan, $stateEval, $runMeta)) {
            return $this->nextPostDiscoveryStep($plan, $stateEval, $runMeta);
        }

        if ($remaining > 0 && $requiresDiscovery && $discoveryAttempts < self::MAX_DISCOVERY_ATTEMPTS) {
            $discoverQty = max(1, min(100, $remaining));

            return [
                'step_key' => WorkflowStepTypes::DISCOVER.'_'.($discoveryAttempts + 1),
                'sequence' => ($discoveryAttempts + 1),
                'tool_name' => 'discover_prospects',
                'step_type' => WorkflowStepTypes::DISCOVER,
                'arguments' => [
                    'target_count' => $discoverQty,
                    'segment' => $plan['objective']['segment'] ?? null,
                    'new_only' => $stateEval['new_only'] ?? false,
                    'platform' => $this->platformResolver->resolve($plan),
                ],
                'approval_required' => false,
            ];
        }

        if ($requested > 0 && $remaining > 0 && $discoveryAttempts >= self::MAX_DISCOVERY_ATTEMPTS) {
            return null;
        }

        if ($requested > 0 && $remaining > 0 && ! $requiresDiscovery) {
            return null;
        }

        return $this->nextPostDiscoveryStep($plan, $stateEval, $runMeta);
    }

    /**
     * Prospect quota met — plan prepare / approval / execute phases.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $stateEval
     * @param  array<string, mixed>  $runMeta
     * @return array<string, mixed>|null
     */
    private function nextPostDiscoveryStep(array $plan, array $stateEval, array $runMeta): ?array
    {
        $outcome = (string) ($plan['required_outcome'] ?? '');

        if ($outcome === 'find_only') {
            return null;
        }

        $prepared = (bool) ($runMeta['prepared'] ?? false);
        $executed = (bool) ($runMeta['executed'] ?? false);
        $awaitingApproval = (bool) ($runMeta['awaiting_approval'] ?? false);

        if (! $prepared) {
            $eligible = max(
                (int) ($stateEval['intersection_eligible_count'] ?? 0),
                $this->discoveredProspectCount($runMeta),
            );

            return [
                'step_key' => WorkflowStepTypes::PREPARE_OUTREACH,
                'sequence' => 90,
                'tool_name' => 'draft_campaign_plan',
                'step_type' => WorkflowStepTypes::PREPARE_OUTREACH,
                'arguments' => [
                    'eligible_count' => $eligible,
                    'setup_only' => $outcome === 'setup_only',
                ],
                'approval_required' => false,
            ];
        }

        if ($outcome === 'setup_only') {
            return null;
        }

        if ($outcome === 'send_now' && ! $executed && ! $awaitingApproval) {
            return [
                'step_key' => WorkflowStepTypes::AWAITING_APPROVAL,
                'sequence' => 95,
                'tool_name' => 'awaiting_user_approval',
                'step_type' => WorkflowStepTypes::AWAITING_APPROVAL,
                'arguments' => [
                    'approval_id' => (int) ($runMeta['approval_id'] ?? 0),
                    'eligible_count' => $stateEval['intersection_eligible_count'] ?? 0,
                ],
                'approval_required' => true,
            ];
        }

        return null;
    }

    /**
     * Whether the prospect quota (discovery phase) is satisfied.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $stateEval
     */
    public function isProspectQuotaMet(array $plan, array $stateEval, array $runMeta = []): bool
    {
        $requested = (int) ($stateEval['requested_quantity'] ?? 0);
        if ($requested <= 0) {
            return true;
        }

        $discovered = $this->discoveredProspectCount($runMeta);

        // Semantic new_only / discover_new: only prospects found in THIS workflow run count.
        if ($this->requiresNetNewDiscovery($stateEval)) {
            return $discovered >= $requested;
        }

        $intersection = (int) ($stateEval['intersection_eligible_count'] ?? 0);
        if ($intersection >= $requested) {
            return true;
        }

        // Freshly discovered lists may not match segment tokens in listMatch().
        return $discovered >= $requested;
    }

    /**
     * @param  array<string, mixed>  $stateEval
     */
    public function requiresNetNewDiscovery(array $stateEval): bool
    {
        if ($stateEval['new_only'] ?? false) {
            return true;
        }

        $preference = (string) ($stateEval['data_preference'] ?? '');

        return $preference === 'new_only' || $preference === 'discover_new';
    }

    /**
     * @param  array<string, mixed>  $stateEval
     * @param  array<string, mixed>  $runMeta
     */
    public function effectiveRemainingDiscovery(array $stateEval, array $runMeta): int
    {
        $requested = (int) ($stateEval['requested_quantity'] ?? 0);
        if ($requested <= 0) {
            return 0;
        }

        if ($this->requiresNetNewDiscovery($stateEval)) {
            return max(0, $requested - $this->discoveredProspectCount($runMeta));
        }

        return (int) ($stateEval['remaining_deficit'] ?? $stateEval['remaining_discovery'] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $runMeta
     */
    private function discoveredProspectCount(array $runMeta): int
    {
        $fromDelta = (int) ($runMeta['cumulative_candidate_delta'] ?? 0);
        if ($fromDelta > 0) {
            return $fromDelta;
        }

        $lists = is_array($runMeta['discovery_lists'] ?? null) ? $runMeta['discovery_lists'] : [];
        if ($lists === []) {
            return 0;
        }

        return array_sum(array_map(
            fn (array $row) => (int) ($row['total_leads'] ?? 0),
            $lists,
        ));
    }

    /**
     * Whether the entire workflow run has reached a terminal success state.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $stateEval
     * @param  array<string, mixed>  $runMeta
     */
    public function isWorkflowComplete(array $plan, array $stateEval, array $runMeta = []): bool
    {
        $outcome = (string) ($plan['required_outcome'] ?? '');

        if ($outcome === 'find_only') {
            return $this->isProspectQuotaMet($plan, $stateEval, $runMeta);
        }

        if (! $this->isProspectQuotaMet($plan, $stateEval, $runMeta)) {
            return false;
        }

        if ($outcome === 'setup_only') {
            return (bool) ($runMeta['prepared'] ?? false);
        }

        if ($outcome === 'send_now') {
            return (bool) ($runMeta['executed'] ?? false);
        }

        return $this->isProspectQuotaMet($plan, $stateEval, $runMeta);
    }

    /**
     * @param  array<string, mixed>  $stateEval
     * @param  array<string, mixed>  $runMeta
     */
    public function isOutcomeSatisfied(array $plan, array $stateEval, array $runMeta = []): bool
    {
        return $this->isWorkflowComplete($plan, $stateEval, $runMeta);
    }

    /**
     * @param  array<string, mixed>  $stateEval
     */
    public function isBlocked(array $stateEval, array $runMeta = []): bool
    {
        $requested = (int) ($stateEval['requested_quantity'] ?? 0);
        $remaining = $this->effectiveRemainingDiscovery($stateEval, $runMeta);
        $attempts = (int) ($runMeta['discovery_attempts'] ?? 0);
        $requiresDiscovery = (bool) ($stateEval['requires_external_discovery'] ?? false);

        return $requested > 0
            && $remaining > 0
            && $requiresDiscovery
            && $attempts >= self::MAX_DISCOVERY_ATTEMPTS;
    }
}

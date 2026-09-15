<?php

namespace App\V2\Ai\Support;

/**
 * Single source of truth for who a turn/workflow will contact.
 * Chat count, approval target_count, and campaign max_leads must agree.
 */
final class AudienceCommitment
{
    public const PROVENANCE_DISCOVERED_THIS_RUN = 'discovered_this_run';

    public const PROVENANCE_EXPLICIT_USER = 'explicit_user';

    public const PROVENANCE_REUSE_APPROVED = 'reuse_approved';

    /**
     * @param  array<string, mixed>  $plan
     */
    public static function requestedCount(array $plan): int
    {
        $measurable = is_array($plan['measurable_expectations'] ?? null) ? $plan['measurable_expectations'] : [];
        $constraints = is_array($plan['constraints'] ?? null) ? $plan['constraints'] : [];
        $objective = is_array($plan['objective'] ?? null) ? $plan['objective'] : [];

        foreach ([
            $measurable['target_count'] ?? null,
            $constraints['target_count'] ?? null,
            $objective['quantity'] ?? null,
        ] as $value) {
            if ($value !== null && $value !== '' && (int) $value > 0) {
                return (int) $value;
            }
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    public static function wantsExplicitReuse(array $plan): bool
    {
        $constraints = is_array($plan['constraints'] ?? null) ? $plan['constraints'] : [];
        $semantic = is_array($plan['semantic'] ?? null) ? $plan['semantic'] : [];

        $audienceIntent = (string) ($constraints['audience_intent'] ?? $semantic['audience_intent'] ?? '');
        if (in_array($audienceIntent, ['reuse_named', 'explicit_list', 'reuse_any'], true)) {
            return true;
        }

        if (trim((string) ($constraints['audience_ref'] ?? $plan['audience_ref'] ?? $semantic['audience_ref'] ?? '')) !== '') {
            return true;
        }

        $preference = (string) ($constraints['data_preference'] ?? $semantic['data_preference'] ?? '');
        if ($preference === 'reuse_existing_first') {
            return true;
        }

        return (bool) ($constraints['reuse_first'] ?? false);
    }

    /**
     * Quantified find/send without a named/reuse audience should be forced to
     * discover this run (TurnPlanBuilder applies new_only / discover_new).
     *
     * @param  array<string, mixed>  $plan
     */
    public static function shouldForceDiscoverThisRun(array $plan): bool
    {
        if (self::wantsExplicitReuse($plan)) {
            return false;
        }

        $outcome = (string) ($plan['required_outcome'] ?? '');
        if (! in_array($outcome, ['find_only', 'send_now', 'setup_only'], true)) {
            return false;
        }

        return self::requestedCount($plan) > 0;
    }

    /**
     * True once the plan is marked discover-this-run (new_only / discover_new).
     *
     * @param  array<string, mixed>  $plan
     */
    public static function requiresDiscoverThisRun(array $plan): bool
    {
        if (self::wantsExplicitReuse($plan)) {
            return false;
        }

        $constraints = is_array($plan['constraints'] ?? null) ? $plan['constraints'] : [];
        $semantic = is_array($plan['semantic'] ?? null) ? $plan['semantic'] : [];

        if ((bool) ($constraints['new_only'] ?? $semantic['new_only'] ?? false)) {
            return true;
        }

        $preference = (string) ($constraints['data_preference'] ?? $semantic['data_preference'] ?? '');

        return in_array($preference, ['new_only', 'discover_new'], true);
    }

    /**
     * @param  array<string, mixed>  $runMeta
     */
    public static function discoveredThisRunCount(array $runMeta): int
    {
        $fromDelta = (int) ($runMeta['cumulative_candidate_delta'] ?? 0);
        if ($fromDelta > 0) {
            return $fromDelta;
        }

        $lists = is_array($runMeta['discovery_lists'] ?? null) ? $runMeta['discovery_lists'] : [];
        if ($lists === []) {
            return 0;
        }

        $sum = 0;
        foreach ($lists as $row) {
            if (! is_array($row) || ! empty($row['reused_recent'])) {
                continue;
            }
            $sum += (int) ($row['total_leads'] ?? 0);
        }

        return $sum;
    }

    /**
     * Bound contact count for prepare / notify / launch.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $runMeta
     * @param  array<string, mixed>  $stateEval
     */
    public static function boundCount(array $plan, array $runMeta = [], array $stateEval = []): int
    {
        $requested = self::requestedCount($plan);
        if ($requested <= 0 && isset($stateEval['requested_quantity'])) {
            $requested = (int) $stateEval['requested_quantity'];
        }

        $discovered = self::discoveredThisRunCount($runMeta);
        $intersection = (int) ($stateEval['intersection_eligible_count'] ?? 0);

        // This-run discovery always wins over archive intersection (prevents 1→32 leakage).
        if ($discovered > 0) {
            return $requested > 0 ? min($requested, $discovered) : $discovered;
        }

        if (self::wantsExplicitReuse($plan) || ! empty($runMeta['audience_commitment']['list_hash'])) {
            $pool = $intersection > 0 ? $intersection : (int) ($runMeta['audience_commitment']['bound_count'] ?? 0);
            if ($pool <= 0) {
                return max(0, $requested);
            }

            return $requested > 0 ? min($requested, $pool) : $pool;
        }

        // Quantified ask with no discovery yet — commit to requested, not archive size.
        if ($requested > 0) {
            return $requested;
        }

        return max(0, $intersection);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $runMeta
     * @param  array<string, mixed>  $list
     * @return array<string, mixed>
     */
    public static function build(array $plan, array $runMeta, array $list, int $boundCount, string $provenance): array
    {
        return [
            'requested_count' => self::requestedCount($plan),
            'bound_count' => max(0, $boundCount),
            'list_hash' => trim((string) ($list['list_hash'] ?? '')),
            'list_src' => trim((string) ($list['list_src'] ?? '')),
            'list_name' => trim((string) ($list['list_name'] ?? '')),
            'provenance' => $provenance,
            'bound_at' => now()->toIso8601String(),
            'workflow_run_id' => (int) ($runMeta['workflow_run_id'] ?? $plan['workflow_run_id'] ?? 0) ?: null,
        ];
    }
}

<?php

namespace App\V2\Ai\Services;

use App\Models\User;
use App\V2\Outreach\OutreachLeadContactResolver;
use App\V2\Outreach\OutreachLeadReadinessService;

/**
 * Evaluates semantic turn-plan constraints against actual SociFusion workspace state.
 *
 * Data sources (audit):
 * | Requirement          | Source                                      |
 * | new_only             | plan constraints + existing list row counts |
 * | previously contacted | PreviouslyContactedQueryService → v2_outreach_leads |
 * | WhatsApp eligibility | OutreachLeadContactResolver::isReadyForChannel |
 * | quantity / deficit   | computed from requested + eligible counts   |
 * | tenant scope         | user_id on lists; org via outreach campaigns |
 */
class TurnPlanStateEvaluationService
{
    public function __construct(
        private readonly ProspectListMatchService $listMatch,
        private readonly OutreachLeadReadinessService $readiness,
        private readonly PreviouslyContactedQueryService $contactedQuery,
        private readonly OutreachLeadContactResolver $contactResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $plan  Turn plan from TurnPlanBuilderService
     * @return array<string, mixed>
     */
    public function evaluate(User $user, int $organizationId, array $plan): array
    {
        $outcome = (string) ($plan['required_outcome'] ?? '');
        if (in_array($outcome, ['status_only', 'clarify', 'delete_now'], true)) {
            return $this->skipped('read_only_or_destructive_outcome');
        }

        $constraints = is_array($plan['constraints'] ?? null) ? $plan['constraints'] : [];
        $semantic = is_array($plan['semantic'] ?? null) ? $plan['semantic'] : [];

        $dataPreference = (string) ($constraints['data_preference'] ?? $semantic['data_preference'] ?? '');
        $newOnly = (bool) ($constraints['new_only'] ?? $semantic['new_only'] ?? false)
            || in_array($dataPreference, ['new_only', 'discover_new'], true);
        $excludeContacted = (bool) ($constraints['exclude_contacted'] ?? $semantic['exclude_previously_contacted'] ?? false);
        $reuseFirst = $dataPreference === 'reuse_existing_first'
            || (bool) ($constraints['reuse_first'] ?? false);
        $channel = $this->normalizeChannel($constraints['preferred_channel'] ?? $semantic['preferred_channel'] ?? null);
        $requested = $this->requestedQuantity($plan);

        $segment = trim((string) (
            $plan['objective']['segment']
            ?? $semantic['target_segment']
            ?? ''
        ));

        $constraintsApplied = [];
        if ($newOnly) {
            $constraintsApplied[] = 'new_only';
        }
        if ($excludeContacted) {
            $constraintsApplied[] = 'exclude_previously_contacted';
        }
        if ($channel !== null) {
            $constraintsApplied[] = $channel;
        }
        if ($reuseFirst) {
            $constraintsApplied[] = 'reuse_existing_first';
        }
        if ($requested !== null) {
            $constraintsApplied[] = 'quantity';
        }

        $matchedLists = $this->listMatch->matchForSegment($user->id, $segment !== '' ? $segment : null);
        $leadLists = array_map(fn (array $row) => [
            'list_hash' => $row['list_hash'],
            'list_src' => $row['list_src'],
        ], $matchedLists);

        $rows = $leadLists !== [] ? $this->readiness->collectLeadRows($leadLists, $user->id) : [];
        $candidateCount = count($rows);

        $contactedKeys = $excludeContacted || $outcome !== 'status_only'
            ? $this->contactedQuery->contactedIdentityKeys($user, $organizationId)
            : [];

        $previouslyContactedCount = 0;
        $eligibleRows = [];

        foreach ($rows as $row) {
            $wasContacted = $this->contactedQuery->rowWasContacted($row, $contactedKeys);
            if ($wasContacted) {
                $previouslyContactedCount++;
            }
            if ($excludeContacted && $wasContacted) {
                continue;
            }
            $eligibleRows[] = $row;
        }

        $excludedCount = $excludeContacted ? $previouslyContactedCount : 0;
        $existingEligibleCount = count($eligibleRows);

        $channelEligibleCount = null;
        $channelIneligibleCount = null;
        $intersectionEligibleCount = $this->intersectionEligibleCount($rows, $contactedKeys, $excludeContacted, $channel);
        $eligibleCount = $channel !== null ? $intersectionEligibleCount : $existingEligibleCount;

        if ($channel !== null) {
            $channelEligibleCount = 0;
            foreach ($rows as $row) {
                if ($this->contactResolver->isReadyForChannel($row, $channel)) {
                    $channelEligibleCount++;
                }
            }
            $channelIneligibleCount = max(0, $candidateCount - $channelEligibleCount);
        }

        $newRequired = null;
        $remainingDiscovery = null;
        $remainingDeficit = null;
        $requiresExternalDiscovery = false;

        if ($reuseFirst && ! $newOnly && $outcome !== 'find_only') {
            $requiresExternalDiscovery = false;
        } elseif ($newOnly && $requested !== null) {
            $newRequired = $requested;
            $remainingDiscovery = $requested;
            $requiresExternalDiscovery = true;
            // Net-new intent: existing pool size does not reduce the discovery requirement.
            $remainingDeficit = $requested;
        } elseif ($requested !== null) {
            $remainingDiscovery = max(0, $requested - $intersectionEligibleCount);
            $requiresExternalDiscovery = $remainingDiscovery > 0;
            $remainingDeficit = max(0, $requested - $intersectionEligibleCount);
        } elseif ($newOnly) {
            $requiresExternalDiscovery = true;
        }

        if ($reuseFirst && $outcome === 'send_now' && ! $newOnly) {
            $requiresExternalDiscovery = false;
            $remainingDiscovery = 0;
        }

        $evaluation = [
            'evaluated_at' => now()->toIso8601String(),
            'skipped' => false,
            'skip_reason' => null,
            'requested_quantity' => $requested,
            'candidate_count' => $candidateCount,
            'existing_eligible_count' => $existingEligibleCount,
            'previously_contacted_count' => $previouslyContactedCount,
            'excluded_count' => $excludedCount,
            'eligible_count' => $eligibleCount,
            'intersection_eligible_count' => $intersectionEligibleCount,
            'new_only' => $newOnly,
            'reuse_existing_first' => $reuseFirst,
            'new_required' => $newRequired,
            'remaining_discovery' => $remainingDiscovery,
            'remaining_deficit' => $remainingDeficit,
            'channel' => $channel,
            'channel_eligible_count' => $channelEligibleCount,
            'channel_ineligible_count' => $channelIneligibleCount,
            'requires_external_discovery' => $requiresExternalDiscovery,
            'data_preference' => $dataPreference !== '' ? $dataPreference : null,
            'constraints_applied' => $constraintsApplied,
            'matched_list_count' => count($matchedLists),
            'prompt_summary' => array_filter([
                'requested' => $requested,
                'candidates' => $candidateCount,
                'eligible' => $eligibleCount,
                'intersection_eligible' => $intersectionEligibleCount,
                'existing_eligible' => $existingEligibleCount,
                'excluded_contacted' => $excludedCount,
                'new_only' => $newOnly,
                'new_required' => $newRequired,
                'remaining_discovery' => $remainingDiscovery,
                'remaining_deficit' => $remainingDeficit,
                'channel' => $channel,
                'channel_eligible' => $channelEligibleCount,
                'requires_external_discovery' => $requiresExternalDiscovery,
            ], fn ($v) => $v !== null),
        ];

        return $evaluation;
    }

    /**
     * Compare current evaluation to workflow baseline for post-step deltas.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $baseline
     * @return array<string, mixed>
     */
    public function deltaFromBaseline(array $current, array $baseline, array $plan): array
    {
        $newOnly = (bool) ($current['new_only'] ?? false);
        $requested = (int) ($current['requested_quantity'] ?? 0);

        $candidateDelta = max(0, (int) ($current['candidate_count'] ?? 0) - (int) ($baseline['candidate_count'] ?? 0));
        $intersectionDelta = max(0, (int) ($current['intersection_eligible_count'] ?? 0) - (int) ($baseline['intersection_eligible_count'] ?? 0));

        if ($newOnly && $requested > 0) {
            $satisfied = $candidateDelta;
            $remaining = max(0, $requested - $candidateDelta);
        } elseif ($requested > 0) {
            $satisfied = (int) ($current['intersection_eligible_count'] ?? 0);
            $remaining = max(0, $requested - $satisfied);
        } else {
            $satisfied = (int) ($current['intersection_eligible_count'] ?? 0);
            $remaining = 0;
        }

        return [
            'candidate_delta' => $candidateDelta,
            'intersection_delta' => $intersectionDelta,
            'satisfied_count' => $satisfied,
            'remaining_requirement' => $remaining,
            'duplicates_estimated' => max(0, $candidateDelta - $intersectionDelta),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, true>  $contactedKeys
     */
    private function intersectionEligibleCount(
        array $rows,
        array $contactedKeys,
        bool $excludeContacted,
        ?string $channel,
    ): int {
        $count = 0;
        foreach ($rows as $row) {
            if ($excludeContacted && $this->contactedQuery->rowWasContacted($row, $contactedKeys)) {
                continue;
            }
            if ($channel !== null && ! $this->contactResolver->isReadyForChannel($row, $channel)) {
                continue;
            }
            $count++;
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $evaluation
     */
    public function promptContext(array $evaluation): ?string
    {
        if ($evaluation['skipped'] ?? true) {
            return null;
        }

        $summary = $evaluation['prompt_summary'] ?? [];
        if ($summary === []) {
            return null;
        }

        return '[Turn plan state (from workspace DB, use these facts — do not invent counts): '
            .json_encode($summary, JSON_UNESCAPED_SLASHES).']';
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function requestedQuantity(array $plan): ?int
    {
        $expectations = is_array($plan['measurable_expectations'] ?? null) ? $plan['measurable_expectations'] : [];
        $qty = $expectations['target_count'] ?? null;
        if (is_numeric($qty) && (int) $qty > 0) {
            return (int) $qty;
        }

        $constraints = is_array($plan['constraints'] ?? null) ? $plan['constraints'] : [];
        $qty = $constraints['target_count'] ?? null;

        return is_numeric($qty) && (int) $qty > 0 ? (int) $qty : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function skipped(string $reason): array
    {
        return [
            'evaluated_at' => now()->toIso8601String(),
            'skipped' => true,
            'skip_reason' => $reason,
            'requested_quantity' => null,
            'candidate_count' => 0,
            'existing_eligible_count' => 0,
            'previously_contacted_count' => 0,
            'excluded_count' => 0,
            'eligible_count' => 0,
            'new_only' => false,
            'reuse_existing_first' => false,
            'new_required' => null,
            'remaining_discovery' => null,
            'remaining_deficit' => null,
            'channel' => null,
            'channel_eligible_count' => null,
            'channel_ineligible_count' => null,
            'requires_external_discovery' => false,
            'constraints_applied' => [],
            'matched_list_count' => 0,
            'prompt_summary' => [],
        ];
    }

    private function normalizeChannel(mixed $channel): ?string
    {
        $channel = is_string($channel) ? strtolower(trim($channel)) : null;

        return in_array($channel, ['whatsapp', 'linkedin', 'email', 'instagram', 'telegram'], true)
            ? $channel
            : null;
    }
}

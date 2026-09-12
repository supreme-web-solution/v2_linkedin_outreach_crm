<?php

namespace App\V2\Ai\Support;

/**
 * Structured state evaluation attached to turn plans after semantic planning.
 *
 * All counts are derived from Laravel/DB — never from LLM inference.
 *
 * @phpstan-type StateEvaluation array{
 *   evaluated_at: string,
 *   skipped: bool,
 *   skip_reason: string|null,
 *   requested_quantity: int|null,
 *   candidate_count: int,
 *   existing_eligible_count: int,
 *   previously_contacted_count: int,
 *   excluded_count: int,
 *   eligible_count: int,
 *   new_only: bool,
 *   reuse_existing_first: bool,
 *   new_required: int|null,
 *   remaining_discovery: int|null,
 *   remaining_deficit: int|null,
 *   channel: string|null,
 *   channel_eligible_count: int|null,
 *   channel_ineligible_count: int|null,
 *   requires_external_discovery: bool,
 *   constraints_applied: list<string>,
 *   matched_list_count: int,
 *   prompt_summary: array<string, int|string|bool|null>
 * }
 */
final class TurnPlanStateEvaluationContract
{
    /**
     * Keys exposed in agent prompt summaries (concise, factual).
     *
     * @return list<string>
     */
    public static function promptSummaryKeys(): array
    {
        return [
            'requested',
            'candidates',
            'eligible',
            'existing_eligible',
            'excluded_contacted',
            'new_only',
            'new_required',
            'remaining_discovery',
            'remaining_deficit',
            'channel',
            'channel_eligible',
            'requires_external_discovery',
        ];
    }
}

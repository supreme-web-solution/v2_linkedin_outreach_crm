<?php

namespace App\V2\Ai\Services;

/**
 * Regex fallback planner — used only when SemanticTurnPlanService is unavailable.
 * Do not extend with new phrase handlers; add meaning to the LLM semantic planner instead.
 */
class IntentGoalResolverService
{
    /**
     * @return array{
     *   goal:string,
     *   required_outcome:string,
     *   side_effect_budget:string,
     *   constraints:array<string,mixed>
     * }
     */
    public function resolve(string $message): array
    {
        $intent = app(UserTurnIntentService::class);
        $count = app(DiscoverProspectsService::class)->inferCountFromQuery($message);
        $lower = strtolower(trim($message));
        $objective = $this->buildObjective($message, $count);
        $constraints = $this->buildConstraints($intent, $message, $count);

        if ($intent->isInformational($message)) {
            return [
                'goal' => 'reporting',
                'required_outcome' => 'status_only',
                'side_effect_budget' => 'read_only',
                'desired_operation' => 'report',
                'objective' => $objective,
                'constraints' => $constraints,
            ];
        }

        if ($this->isDeleteIntent($lower)) {
            return [
                'goal' => 'management',
                'required_outcome' => 'delete_now',
                'side_effect_budget' => 'destructive_allowed',
                'desired_operation' => 'delete',
                'objective' => $objective,
                'constraints' => $constraints,
            ];
        }

        if ($intent->wantsCampaignSetupOnly($message)) {
            return [
                'goal' => 'outreach',
                'required_outcome' => 'setup_only',
                'side_effect_budget' => 'prepare_only',
                'desired_operation' => 'prepare_outreach',
                'objective' => $objective,
                'constraints' => array_merge($constraints, ['reuse_first' => true]),
            ];
        }

        if ($intent->isOutreachCommand($message)) {
            return [
                'goal' => 'outreach',
                'required_outcome' => 'send_now',
                'side_effect_budget' => 'external_send_allowed',
                'desired_operation' => 'contact_prospects',
                'objective' => $objective,
                'constraints' => array_merge($constraints, ['reuse_first' => true]),
            ];
        }

        if ($this->isExecutionIntent($lower)) {
            return [
                'goal' => 'management',
                'required_outcome' => 'execute_now',
                'side_effect_budget' => 'external_send_allowed',
                'desired_operation' => 'execute',
                'objective' => $objective,
                'constraints' => $constraints,
            ];
        }

        if ($intent->isDiscoveryOnly($message) || $intent->isProspectDiscoveryRequest($message)) {
            return [
                'goal' => 'discovery',
                'required_outcome' => 'find_only',
                'side_effect_budget' => 'mutate_allowed',
                'desired_operation' => 'find_and_save',
                'objective' => $objective,
                'constraints' => $constraints,
            ];
        }

        return [
            'goal' => 'management',
            'required_outcome' => 'general_assist',
            'side_effect_budget' => 'mutate_allowed',
            'desired_operation' => 'assist',
            'objective' => $objective,
            'constraints' => $constraints,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildObjective(string $message, ?int $count): array
    {
        return [
            'entity' => 'prospects',
            'quantity' => $count,
            'criteria' => trim($message),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildConstraints(UserTurnIntentService $intent, string $message, ?int $count): array
    {
        $lower = strtolower($message);

        return [
            'target_count' => $count,
            'new_only' => $intent->wantsFreshProspectPull($message),
            'instagram_requested' => $intent->explicitDiscoveryChannel($message) === 'instagram',
            'exclude_contacted' => (bool) preg_match('/\b(ignore|exclude|skip)\b.{0,30}\b(contacted|already contacted|existing)\b/i', $lower),
            'preferred_channel' => match (true) {
                (bool) preg_match('/\bwhatsapp\b/i', $lower) => 'whatsapp',
                ($channel = $intent->explicitDiscoveryChannel($message)) !== null => $channel,
                default => null,
            },
            'scheduled_for' => (bool) preg_match('/\b(tomorrow|next day|morning|afternoon|evening|at\s+\d{1,2})\b/i', $lower) ? 'requested' : null,
        ];
    }

    private function isDeleteIntent(string $lower): bool
    {
        // Include common typo "delet" — do not require perfect spelling for destructive intent.
        if (! (bool) preg_match('/\b(delet(?:e)?|remove|wipe|get rid of|erase|clear|purge|trash)\b/i', $lower)) {
            return false;
        }

        return (bool) preg_match('/\b(campaign|campaigns|lead|leads|list|lists|contact|contacts|prospect|prospects|audience|audiences|saved)\b/i', $lower);
    }

    private function isExecutionIntent(string $lower): bool
    {
        $verb = (bool) preg_match('/\b(activate|start|run|pause|send|book|launch)\b/i', $lower);
        $entity = (bool) preg_match('/\b(campaign|campaigns|outreach|message|messages|reply|replies|meeting|meetings)\b/i', $lower);

        return $verb && $entity;
    }
}

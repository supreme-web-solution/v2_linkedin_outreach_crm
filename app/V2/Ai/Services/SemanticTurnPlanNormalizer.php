<?php

namespace App\V2\Ai\Services;

use App\V2\Ai\Support\SemanticTurnPlanContract;
use Illuminate\Support\Str;

/**
 * Maps LLM semantic interpretation → governed TurnPlan (enforcement contract).
 * No phrase routing — only field normalization and policy mapping.
 */
class SemanticTurnPlanNormalizer
{
    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function sanitizeSemantic(array $raw): array
    {
        $out = [];
        foreach (SemanticTurnPlanContract::requiredKeys() as $key) {
            $out[$key] = $raw[$key] ?? $this->defaultFor($key);
        }

        $out['user_objective'] = $this->enum(
            (string) $out['user_objective'],
            SemanticTurnPlanContract::OBJECTIVES,
            'general_assist',
        );
        $out['execution_mode'] = $this->enum(
            (string) $out['execution_mode'],
            SemanticTurnPlanContract::EXECUTION_MODES,
            'clarify',
        );
        $out['data_preference'] = $this->enum(
            (string) $out['data_preference'],
            SemanticTurnPlanContract::DATA_PREFERENCES,
            'unspecified',
        );

        $qty = $out['quantity'];
        $out['quantity'] = is_numeric($qty) ? max(0, min(100, (int) $qty)) : null;

        foreach (['new_only', 'exclude_previously_contacted', 'decision_maker_required', 'prepare_only', 'send_requested', 'delete_requested', 'requires_clarification'] as $flag) {
            $out[$flag] = (bool) $out[$flag];
        }

        $channel = strtolower(trim((string) ($out['preferred_channel'] ?? '')));
        $out['preferred_channel'] = in_array($channel, ['whatsapp', 'linkedin', 'email', 'instagram', 'telegram'], true)
            ? $channel
            : null;

        $out['target_segment'] = is_string($out['target_segment']) ? Str::limit(trim($out['target_segment']), 200, '') : null;
        $out['geography'] = is_string($out['geography']) ? Str::limit(trim($out['geography']), 120, '') : null;
        $out['schedule_hint'] = is_string($out['schedule_hint']) ? Str::limit(trim($out['schedule_hint']), 120, '') : null;
        $out['clarification_reason'] = is_string($out['clarification_reason']) ? Str::limit(trim($out['clarification_reason']), 300, '') : null;
        $out['ambiguous_referent'] = is_string($out['ambiguous_referent']) ? trim($out['ambiguous_referent']) : null;
        $out['confidence'] = max(0.0, min(1.0, (float) ($out['confidence'] ?? 0.5)));

        if ($out['requires_clarification']) {
            $out['execution_mode'] = 'clarify';
        }

        return $out;
    }

    /**
     * Reinforce incremental discovery intent when the LLM missed "more/additional" phrasing.
     *
     * @param  array<string, mixed>  $semantic
     * @return array<string, mixed>
     */
    private function applyIncrementalDiscoverySemantics(array $semantic, string $originalMessage): array
    {
        if ($semantic['execution_mode'] === 'read_only') {
            return $semantic;
        }

        if ($semantic['data_preference'] === 'reuse_existing_first') {
            return $semantic;
        }

        if (! preg_match('/\b(more|additional|another|extra)\b/i', $originalMessage)) {
            return $semantic;
        }

        $semantic['new_only'] = true;
        if (in_array($semantic['data_preference'], ['unspecified', 'reuse_existing_first'], true)) {
            $semantic['data_preference'] = 'discover_new';
        }

        return $semantic;
    }

    /**
     * @param  array<string, mixed>  $semantic
     * @return array<string, mixed>
     */
    public function toEnforcementPlan(array $semantic, string $originalMessage): array
    {
        $semantic = $this->sanitizeSemantic($semantic);
        $semantic = $this->applyIncrementalDiscoverySemantics($semantic, $originalMessage);

        [$goal, $requiredOutcome, $sideEffectBudget] = $this->resolveEnforcement($semantic);

        $quantity = $semantic['quantity'];
        $newOnly = (bool) $semantic['new_only']
            || in_array($semantic['data_preference'], ['new_only', 'discover_new'], true);

        $constraints = [
            'target_count' => $quantity,
            'new_only' => $newOnly,
            'exclude_contacted' => (bool) $semantic['exclude_previously_contacted'],
            'decision_maker_required' => (bool) $semantic['decision_maker_required'],
            'preferred_channel' => $semantic['preferred_channel'],
            'geography' => $semantic['geography'],
            'scheduled_for' => $semantic['schedule_hint'],
            'data_preference' => $semantic['data_preference'],
            'reuse_first' => $semantic['data_preference'] === 'reuse_existing_first',
            'prepare_only' => (bool) $semantic['prepare_only'],
            'send_requested' => (bool) $semantic['send_requested'],
            'delete_requested' => (bool) $semantic['delete_requested'],
            'requires_clarification' => (bool) $semantic['requires_clarification'],
            'clarification_reason' => $semantic['clarification_reason'],
            'ambiguous_referent' => $semantic['ambiguous_referent'],
        ];

        return [
            'goal' => $goal,
            'objective' => [
                'entity' => $semantic['target_entity'],
                'segment' => $semantic['target_segment'],
                'quantity' => $quantity,
                'criteria' => trim($originalMessage),
            ],
            'desired_operation' => $this->desiredOperation($semantic),
            'required_outcome' => $requiredOutcome,
            'side_effect_budget' => $sideEffectBudget,
            'constraints' => array_filter($constraints, fn ($v) => $v !== null && $v !== false && $v !== []),
            'semantic' => $semantic,
            'semantic_source' => 'llm',
            'execution_preferences' => [
                'approval_required' => in_array($requiredOutcome, ['send_now', 'delete_now', 'execute_now'], true),
                'source' => $semantic['data_preference'],
            ],
            'measurable_expectations' => [
                'target_count' => $quantity,
            ],
            'built_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Fingerprint for semantic equivalence tests (ignores surface wording).
     *
     * @param  array<string, mixed>  $semantic
     */
    public function equivalenceFingerprint(array $semantic): string
    {
        $semantic = $this->sanitizeSemantic($semantic);
        $keys = [
            'user_objective',
            'target_entity',
            'quantity',
            'new_only',
            'exclude_previously_contacted',
            'decision_maker_required',
            'preferred_channel',
            'data_preference',
            'execution_mode',
            'prepare_only',
            'send_requested',
            'delete_requested',
            'requires_clarification',
        ];
        $slice = [];
        foreach ($keys as $key) {
            $value = $semantic[$key] ?? null;
            if ($key === 'target_entity' && is_string($value)) {
                $value = $this->canonicalEntityType($value);
            }
            $slice[$key] = $value;
        }

        return hash('sha256', json_encode($slice, JSON_THROW_ON_ERROR));
    }

    private function canonicalEntityType(string $entity): string
    {
        $entity = Str::lower(trim($entity));

        return match ($entity) {
            'company', 'companies', 'prospect', 'prospects', 'founder', 'founders',
            'lead', 'leads', 'people', 'person', 'customer', 'customers', 'client', 'clients' => 'prospect_targets',
            default => $entity,
        };
    }

    /**
     * Derive enforcement contract from structured semantic fields only.
     *
     * @param  array<string, mixed>  $semantic  Already sanitized
     * @return array{0:string,1:string,2:string}
     */
    private function resolveEnforcement(array $semantic): array
    {
        $mode = (string) $semantic['execution_mode'];
        $objective = (string) $semantic['user_objective'];
        $sendRequested = (bool) $semantic['send_requested'];
        $prepareOnly = (bool) $semantic['prepare_only'];
        $deleteRequested = (bool) $semantic['delete_requested'];

        if ($semantic['requires_clarification'] || $mode === 'clarify') {
            return ['management', 'clarify', 'read_only'];
        }

        if ($deleteRequested || $mode === 'delete') {
            return ['management', 'delete_now', 'destructive_allowed'];
        }

        if ($objective === 'report_state' || $mode === 'read_only') {
            return ['reporting', 'status_only', 'read_only'];
        }

        if (($objective === 'prepare_outreach' || $mode === 'prepare_outreach') && $prepareOnly && ! $sendRequested) {
            return ['outreach', 'setup_only', 'prepare_only'];
        }

        if ($sendRequested && in_array($objective, ['discover_prospects', 'execute_outreach'], true)) {
            return ['outreach', 'send_now', 'external_send_allowed'];
        }

        if ($sendRequested && $mode === 'find_and_save') {
            return ['outreach', 'send_now', 'external_send_allowed'];
        }

        if ($mode === 'find_and_save' || $objective === 'discover_prospects') {
            return ['discovery', 'find_only', 'mutate_allowed'];
        }

        if ($mode === 'send_outreach' || $objective === 'execute_outreach') {
            return ['outreach', 'send_now', 'external_send_allowed'];
        }

        if ($mode === 'prepare_outreach' || $objective === 'prepare_outreach') {
            return ['outreach', 'setup_only', 'prepare_only'];
        }

        if ($mode === 'execute_management') {
            return ['management', 'execute_now', 'external_send_allowed'];
        }

        return match ($mode) {
            'read_only' => ['reporting', 'status_only', 'read_only'],
            default => ['management', 'general_assist', 'read_only'],
        };
    }

    /**
     * @param  array<string, mixed>  $semantic
     */
    private function desiredOperation(array $semantic): string
    {
        [, $requiredOutcome] = $this->resolveEnforcement($semantic);

        return match ($requiredOutcome) {
            'status_only' => 'report',
            'find_only' => 'find_and_save',
            'setup_only' => 'prepare_outreach',
            'send_now' => 'contact_prospects',
            'delete_now' => 'delete',
            'execute_now' => 'execute',
            'clarify' => 'clarify',
            default => 'assist',
        };
    }

    private function defaultFor(string $key): mixed
    {
        return match ($key) {
            'quantity', 'target_segment', 'preferred_channel', 'geography', 'schedule_hint', 'clarification_reason', 'ambiguous_referent' => null,
            'new_only', 'exclude_previously_contacted', 'decision_maker_required', 'prepare_only', 'send_requested', 'delete_requested', 'requires_clarification' => false,
            'confidence' => 0.5,
            'user_objective' => 'general_assist',
            'target_entity' => 'unknown',
            'data_preference' => 'unspecified',
            'execution_mode' => 'clarify',
            default => null,
        };
    }

    /**
     * @param  list<string>  $allowed
     */
    private function enum(string $value, array $allowed, string $fallback): string
    {
        $value = Str::lower(trim($value));

        return in_array($value, $allowed, true) ? $value : $fallback;
    }
}

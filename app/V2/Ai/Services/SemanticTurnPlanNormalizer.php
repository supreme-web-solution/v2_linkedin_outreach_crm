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
        $out['channel_scope'] = $this->enum(
            (string) $out['channel_scope'],
            SemanticTurnPlanContract::CHANNEL_SCOPES,
            'unspecified',
        );
        $out['audience_intent'] = $this->enum(
            (string) $out['audience_intent'],
            SemanticTurnPlanContract::AUDIENCE_INTENTS,
            'unspecified',
        );

        $qty = $out['quantity'];
        $out['quantity'] = is_numeric($qty) ? max(0, min(100, (int) $qty)) : null;

        foreach ([
            'new_only',
            'exclude_previously_contacted',
            'decision_maker_required',
            'prepare_only',
            'send_requested',
            'delete_requested',
            'cold_one_shot',
            'recipient_correction',
            'message_correction',
            'inbox_reply',
            'requires_clarification',
        ] as $flag) {
            $out[$flag] = (bool) $out[$flag];
        }

        $out['preferred_channels'] = $this->normalizeChannels($out['preferred_channels'] ?? []);
        $channel = $this->normalizeChannelToken((string) ($out['preferred_channel'] ?? ''));
        if ($channel === null && $out['preferred_channels'] !== []) {
            $channel = $out['preferred_channels'][0];
        }
        if ($channel !== null && ! in_array($channel, $out['preferred_channels'], true)) {
            array_unshift($out['preferred_channels'], $channel);
        }
        $out['preferred_channel'] = $channel;

        if ($out['channel_scope'] === 'unspecified') {
            if (count($out['preferred_channels']) >= 2) {
                $out['channel_scope'] = 'multi';
            } elseif (count($out['preferred_channels']) === 1) {
                $out['channel_scope'] = 'single';
            }
        }

        if ($out['recipient_correction'] || $out['message_correction']) {
            $out['cold_one_shot'] = true;
        }

        if ($out['inbox_reply']) {
            $out['cold_one_shot'] = false;
            $out['recipient_correction'] = false;
            $out['message_correction'] = false;
        } elseif ($out['cold_one_shot']) {
            $out['inbox_reply'] = false;
        }

        if (in_array($out['audience_intent'], ['reuse_named', 'explicit_list', 'reuse_any'], true)
            && $out['data_preference'] === 'unspecified'
        ) {
            $out['data_preference'] = 'reuse_existing_first';
        }
        if ($out['audience_intent'] === 'discover'
            && $out['data_preference'] === 'unspecified'
        ) {
            $out['data_preference'] = 'discover_new';
        }

        $out['target_segment'] = is_string($out['target_segment']) ? Str::limit(trim($out['target_segment']), 200, '') : null;
        $out['geography'] = is_string($out['geography']) ? Str::limit(trim($out['geography']), 120, '') : null;
        $out['schedule_hint'] = is_string($out['schedule_hint']) ? Str::limit(trim($out['schedule_hint']), 120, '') : null;
        $out['clarification_reason'] = is_string($out['clarification_reason']) ? Str::limit(trim($out['clarification_reason']), 300, '') : null;
        $out['ambiguous_referent'] = is_string($out['ambiguous_referent']) ? trim($out['ambiguous_referent']) : null;
        $out['handoff_brief'] = is_string($out['handoff_brief']) ? Str::limit(trim($out['handoff_brief']), 400, '') : null;
        $out['handoff_query'] = is_string($out['handoff_query']) ? Str::limit(trim($out['handoff_query']), 400, '') : null;
        $out['offer_override'] = is_string($out['offer_override']) ? Str::limit(trim($out['offer_override']), 300, '') : null;
        if ($out['offer_override'] === '') {
            $out['offer_override'] = null;
        }
        $out['audience_ref'] = is_string($out['audience_ref']) ? Str::limit(trim($out['audience_ref']), 200, '') : null;
        if ($out['audience_ref'] === '') {
            $out['audience_ref'] = null;
        }
        $out['confidence'] = max(0.0, min(1.0, (float) ($out['confidence'] ?? 0.5)));

        if ($out['requires_clarification']) {
            $out['execution_mode'] = 'clarify';
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $semantic
     * @return array<string, mixed>
     */
    public function toEnforcementPlan(array $semantic, string $originalMessage): array
    {
        $semantic = $this->sanitizeSemantic($semantic);

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
            'preferred_channels' => $semantic['preferred_channels'],
            'channel_scope' => $semantic['channel_scope'],
            'geography' => $semantic['geography'],
            'scheduled_for' => $semantic['schedule_hint'],
            'data_preference' => $semantic['data_preference'],
            'reuse_first' => $semantic['data_preference'] === 'reuse_existing_first'
                || in_array($semantic['audience_intent'], ['reuse_any', 'reuse_named', 'explicit_list'], true),
            'prepare_only' => (bool) $semantic['prepare_only'],
            'send_requested' => (bool) $semantic['send_requested'],
            'delete_requested' => (bool) $semantic['delete_requested'],
            'cold_one_shot' => (bool) $semantic['cold_one_shot'],
            'recipient_correction' => (bool) $semantic['recipient_correction'],
            'message_correction' => (bool) $semantic['message_correction'],
            'offer_override' => $semantic['offer_override'],
            'inbox_reply' => (bool) $semantic['inbox_reply'],
            'audience_intent' => $semantic['audience_intent'],
            'audience_ref' => $semantic['audience_ref'],
            'requires_clarification' => (bool) $semantic['requires_clarification'],
            'clarification_reason' => $semantic['clarification_reason'],
            'ambiguous_referent' => $semantic['ambiguous_referent'],
        ];

        if (is_string($semantic['audience_ref'] ?? null) && trim((string) $semantic['audience_ref']) !== '') {
            $constraints['list_name'] = trim((string) $semantic['audience_ref']);
        }

        return [
            'goal' => $goal,
            'objective' => [
                'entity' => $semantic['target_entity'],
                'segment' => $semantic['target_segment'],
                'quantity' => $quantity,
                'criteria' => trim((string) ($semantic['handoff_query'] ?? '')) !== ''
                    ? $semantic['handoff_query']
                    : trim($originalMessage),
            ],
            'handoff_brief' => $semantic['handoff_brief'],
            'handoff_query' => $semantic['handoff_query'],
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
            'preferred_channels',
            'channel_scope',
            'data_preference',
            'execution_mode',
            'prepare_only',
            'send_requested',
            'delete_requested',
            'cold_one_shot',
            'recipient_correction',
            'message_correction',
            'inbox_reply',
            'audience_intent',
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

        // Explicit interaction modes from the semantic planner — do not mis-route as find_only.
        if ((bool) ($semantic['inbox_reply'] ?? false)) {
            return $prepareOnly && ! $sendRequested
                ? ['outreach', 'setup_only', 'prepare_only']
                : ['outreach', 'send_now', 'external_send_allowed'];
        }

        if ((bool) ($semantic['cold_one_shot'] ?? false)
            || (bool) ($semantic['recipient_correction'] ?? false)
            || (bool) ($semantic['message_correction'] ?? false)
        ) {
            return $prepareOnly && ! $sendRequested
                ? ['outreach', 'setup_only', 'prepare_only']
                : ['outreach', 'send_now', 'external_send_allowed'];
        }

        if ($objective === 'report_state' || $mode === 'read_only') {
            return ['reporting', 'status_only', 'read_only'];
        }

        if ($prepareOnly && ! $sendRequested && in_array($objective, [
            'prepare_outreach',
            'execute_outreach',
            'discover_prospects',
        ], true)) {
            return ['outreach', 'setup_only', 'prepare_only'];
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
            'quantity', 'target_segment', 'preferred_channel', 'geography', 'schedule_hint', 'clarification_reason', 'ambiguous_referent', 'handoff_brief', 'handoff_query', 'offer_override', 'audience_ref' => null,
            'preferred_channels' => [],
            'channel_scope' => 'unspecified',
            'audience_intent' => 'unspecified',
            'new_only', 'exclude_previously_contacted', 'decision_maker_required', 'prepare_only', 'send_requested', 'delete_requested', 'cold_one_shot', 'recipient_correction', 'message_correction', 'inbox_reply', 'requires_clarification' => false,
            'confidence' => 0.5,
            'user_objective' => 'general_assist',
            'target_entity' => 'unknown',
            'data_preference' => 'unspecified',
            'execution_mode' => 'clarify',
            default => null,
        };
    }

    /**
     * @param  mixed  $raw
     * @return list<string>
     */
    private function normalizeChannels(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = preg_split('/[,+|\/]/', $raw) ?: [];
        }
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $channel) {
            $normalized = $this->normalizeChannelToken((string) $channel);
            if ($normalized !== null && ! in_array($normalized, $out, true)) {
                $out[] = $normalized;
            }
        }

        return $out;
    }

    private function normalizeChannelToken(string $channel): ?string
    {
        $channel = Str::lower(trim($channel));
        if ($channel === 'x') {
            $channel = 'twitter';
        }

        return in_array($channel, SemanticTurnPlanContract::CHANNELS, true) ? $channel : null;
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

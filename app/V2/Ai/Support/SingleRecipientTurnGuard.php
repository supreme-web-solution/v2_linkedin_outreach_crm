<?php

namespace App\V2\Ai\Support;

/**
 * Domain-agnostic: single-recipient cold outbound must never fan into list discovery.
 * Applies to email, LinkedIn, Instagram, WhatsApp, Telegram, X — not email-only.
 */
final class SingleRecipientTurnGuard
{
    /**
     * @param  array<string, mixed>  $plan
     */
    public static function matches(array $plan, ?string $utterance = null): bool
    {
        $constraints = is_array($plan['constraints'] ?? null) ? $plan['constraints'] : [];
        $semantic = is_array($plan['semantic'] ?? null) ? $plan['semantic'] : [];

        if (! empty($constraints['cold_one_shot'])
            || ! empty($semantic['cold_one_shot'])
            || ! empty($constraints['recipient_correction'])
            || ! empty($semantic['recipient_correction'])
            || ! empty($constraints['message_correction'])
            || ! empty($semantic['message_correction'])
        ) {
            return true;
        }

        if (! empty($plan['one_shot']) || ! empty($constraints['one_shot'])) {
            return true;
        }

        if (! empty($plan['single_channel_only']) || ! empty($constraints['single_channel_only'])) {
            $target = (int) (
                $plan['measurable_expectations']['target_count']
                ?? $constraints['target_count']
                ?? $semantic['quantity']
                ?? 0
            );
            if ($target === 1) {
                return true;
            }
        }

        $source = strtolower(trim((string) ($plan['source'] ?? $constraints['source'] ?? '')));
        if ($source === 'cold_outbound') {
            return true;
        }

        if (is_string($utterance) && trim($utterance) !== '') {
            $identity = app(\App\V2\Ai\Services\UserTurnIntentService::class)
                ->extractColdOutboundIdentity($utterance);
            if (($identity['channel'] ?? null) !== null) {
                // Explicit recipient identity on an outbound ask — never treat as list discovery.
                $intent = app(\App\V2\Ai\Services\UserTurnIntentService::class);
                if ($intent->isColdOutboundRequest($utterance)
                    || ! empty($semantic['cold_one_shot'])
                    || ! empty($constraints['send_requested'])
                    || in_array((string) ($plan['required_outcome'] ?? ''), ['send_now', 'setup_only'], true)
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Planned primary channel for verifier / memory (any channel).
     *
     * @param  array<string, mixed>  $plan
     */
    public static function plannedChannel(array $plan): ?string
    {
        $constraints = is_array($plan['constraints'] ?? null) ? $plan['constraints'] : [];
        $state = is_array($plan['state_evaluation'] ?? null) ? $plan['state_evaluation'] : [];
        $semantic = is_array($plan['semantic'] ?? null) ? $plan['semantic'] : [];

        $channel = strtolower(trim((string) (
            $constraints['preferred_channel']
            ?? $plan['primary_channel']
            ?? $state['channel']
            ?? $semantic['preferred_channel']
            ?? ''
        )));

        if ($channel === 'twitter') {
            $channel = 'twitter';
        }

        return $channel !== '' ? $channel : null;
    }

    /**
     * Tools that must not run on a single-recipient cold turn (list discovery / fan-out).
     *
     * @return list<string>
     */
    public static function blockedListTools(): array
    {
        return [
            'discover_prospects',
            'propose_strategy',
            'save_contacts',
            'prepare_competitor_harvest',
            'prepare_enrichment',
        ];
    }
}

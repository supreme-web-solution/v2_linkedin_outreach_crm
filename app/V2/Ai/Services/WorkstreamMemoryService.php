<?php

namespace App\V2\Ai\Services;

use App\Models\AiConversation;
use Illuminate\Support\Str;

/**
 * Durable workstream memory for Soci — survives across turns (unlike TurnPlanContext).
 * Stored on AiConversation.meta.workstream so the employee remembers list_hash,
 * last research URL, open offer, and current goal without re-asking.
 */
class WorkstreamMemoryService
{
    /**
     * @param  array<string, mixed>  $plan
     */
    public function rememberFromTurnPlan(AiConversation $conversation, array $plan, string $utterance = ''): void
    {
        $constraints = is_array($plan['constraints'] ?? null) ? $plan['constraints'] : [];
        $outcome = $this->stringOrNull($plan['required_outcome'] ?? null);
        $outreachHold = null;
        if ($outcome === 'find_only'
            || ! empty($constraints['prepare_only'])
            || $this->utteranceSaysHoldOutreach($utterance)) {
            $outreachHold = true;
        } elseif (in_array($outcome, ['send_now', 'setup_only'], true)
            && empty($constraints['prepare_only'])
            && ! $this->utteranceSaysHoldOutreach($utterance)) {
            $outreachHold = false;
        }

        $patch = array_filter([
            'goal' => $this->stringOrNull($plan['interpreted_brief'] ?? $plan['goal'] ?? null),
            'required_outcome' => $outcome,
            'preferred_channels' => is_array($constraints['preferred_channels'] ?? null)
                ? array_values(array_filter($constraints['preferred_channels']))
                : null,
            'preferred_channel' => $this->stringOrNull(
                $constraints['preferred_channel']
                ?? \App\V2\Ai\Support\SingleRecipientTurnGuard::plannedChannel($plan)
            ),
            'audience_ref' => $this->stringOrNull($constraints['audience_ref'] ?? $constraints['list_name'] ?? null),
            'list_hash' => $this->stringOrNull($constraints['list_hash'] ?? null),
            'offer_override' => $this->stringOrNull($constraints['offer_override'] ?? null),
            'cold_one_shot' => ! empty($constraints['cold_one_shot']) ? true : null,
            'inbox_reply' => ! empty($constraints['inbox_reply']) ? true : null,
            'outreach_hold' => $outreachHold,
            'single_recipient_turn' => ! empty($plan['single_recipient_turn'])
                || \App\V2\Ai\Support\SingleRecipientTurnGuard::matches($plan, $utterance)
                ? true
                : null,
            'last_utterance' => $utterance !== '' ? Str::limit(trim($utterance), 240, '') : null,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);

        if ($patch === []) {
            return;
        }

        $this->merge($conversation, $patch);
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    public function rememberFacts(AiConversation $conversation, array $facts): void
    {
        $allowed = [
            'list_hash', 'audience_ref', 'research_url', 'offer_override',
            'last_recipient', 'last_channel', 'goal', 'preferred_channel',
            'outreach_hold', 'quality_warning',
        ];
        $patch = [];
        foreach ($allowed as $key) {
            if (! array_key_exists($key, $facts)) {
                continue;
            }
            $value = $facts[$key];
            if (is_string($value)) {
                $value = trim($value);
                if ($value === '') {
                    continue;
                }
                $patch[$key] = Str::limit($value, 400, '');
            } elseif ($value !== null) {
                $patch[$key] = $value;
            }
        }
        if ($patch === []) {
            return;
        }
        $this->merge($conversation, $patch);
    }

    /**
     * @return array<string, mixed>
     */
    public function current(AiConversation $conversation): array
    {
        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $workstream = is_array($meta['workstream'] ?? null) ? $meta['workstream'] : [];

        return $workstream;
    }

    public function promptBlock(AiConversation $conversation): string
    {
        $ws = $this->current($conversation);
        if ($ws === []) {
            return '';
        }

        $lines = ['[Workstream memory — continue this employee thread; do not re-ask facts already listed:]'];
        foreach ([
            'goal' => 'Open goal',
            'required_outcome' => 'Last outcome',
            'list_hash' => 'Active list_hash',
            'audience_ref' => 'Audience',
            'research_url' => 'Research URL',
            'offer_override' => 'Offer angle',
            'last_recipient' => 'Last recipient',
            'last_channel' => 'Last channel',
            'preferred_channel' => 'Preferred channel',
        ] as $key => $label) {
            $value = $ws[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $lines[] = "- {$label}: ".Str::limit(trim($value), 180, '');
            }
        }
        if (! empty($ws['preferred_channels']) && is_array($ws['preferred_channels'])) {
            $lines[] = '- Preferred channels: '.implode(', ', array_slice($ws['preferred_channels'], 0, 6));
        }
        if (array_key_exists('outreach_hold', $ws) && $ws['outreach_hold'] === true) {
            $lines[] = '- Outreach hold: ON — lists may be saved, but do not stage/send outreach until the owner explicitly asks.';
        }
        if (! empty($ws['single_recipient_turn'])) {
            $lines[] = '- Single-recipient cold turn: use draft_cold_outbound only — do not discover lists or fan out to other channels.';
        }
        if (! empty($ws['quality_warning']) && is_string($ws['quality_warning'])) {
            $lines[] = '- Discovery quality: '.Str::limit(trim($ws['quality_warning']), 180, '');
        }

        return count($lines) > 1 ? implode("\n", $lines) : '';
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    private function merge(AiConversation $conversation, array $patch): void
    {
        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $existing = is_array($meta['workstream'] ?? null) ? $meta['workstream'] : [];
        $meta['workstream'] = array_merge($existing, $patch, [
            'updated_at' => now()->toIso8601String(),
        ]);
        $conversation->forceFill(['meta' => $meta])->save();
    }

    private function utteranceSaysHoldOutreach(string $utterance): bool
    {
        $lower = Str::lower(trim($utterance));
        if ($lower === '') {
            return false;
        }

        return (bool) preg_match(
            '/\b(don\'?t|do not|dont)\b.{0,40}\b(reach|outreach|message|email|dm|contact|send)\b|\b(not yet|later|just (find|get|save)|save only)\b/i',
            $lower
        );
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value !== '' ? Str::limit($value, 400, '') : null;
    }
}

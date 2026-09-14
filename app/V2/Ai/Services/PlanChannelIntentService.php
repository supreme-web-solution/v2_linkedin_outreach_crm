<?php

namespace App\V2\Ai\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Read the channels a Command Center plan promised, so launch can execute
 * them instead of collapsing to whatever list LinkedIn happened to return.
 */
class PlanChannelIntentService
{
    public function __construct(
        private readonly AiChannelPolicyService $policy,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public function mentioned(array $payload): array
    {
        $fromArray = $payload['discovery_channels'] ?? null;
        if (is_array($fromArray) && $fromArray !== []) {
            $named = [];
            foreach ($fromArray as $channel) {
                $normalized = strtolower(trim((string) $channel));
                if (in_array($normalized, ['linkedin', 'instagram', 'email', 'whatsapp', 'telegram', 'twitter'], true)) {
                    $named[] = $normalized;
                }
            }
            if ($named !== []) {
                return array_values(array_unique($named));
            }
        }

        return $this->policy->mentionedInPlan($payload);
    }

    /**
     * Platforms we can actually search people on.
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public function discoveryChannels(array $payload): array
    {
        return array_values(array_intersect($this->mentioned($payload), ['linkedin', 'instagram']));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function wantsEmail(array $payload): bool
    {
        return ! empty($payload['include_email'])
            || in_array('email', $this->mentioned($payload), true);
    }

    /**
     * Launch should fan out when the plan named more than one searchable
     * channel and has not already been locked to a single list/channel.
     *
     * @param  array<string, mixed>  $payload
     */
    public function wantsParallelDiscovery(array $payload): bool
    {
        if (! empty($payload['one_shot'])
            || ! empty($payload['cold_one_shot'])
            || strtolower(trim((string) ($payload['source'] ?? ''))) === 'cold_outbound'
            || \App\V2\Ai\Support\SingleRecipientTurnGuard::matches($payload)
        ) {
            return false;
        }

        if (count($this->discoveryChannels($payload)) < 2) {
            return false;
        }

        if (! empty($payload['single_channel_only']) && trim((string) ($payload['list_hash'] ?? '')) !== '') {
            return false;
        }

        return true;
    }

    /**
     * Instagram search wants keywords (offer / niche), not job titles.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $icp
     */
    public function instagramKeyword(array $payload, array $icp = []): string
    {
        // Prefer buyer niches / who-we-sell-to over seller pitch (avoids celebrity keyword noise).
        $niches = Arr::get($icp, 'niches', []);
        if (is_array($niches) && $niches !== []) {
            $nicheLine = trim(implode(' ', array_map(fn ($v) => (string) $v, array_slice($niches, 0, 4))));
            if ($nicheLine !== '' && ! $this->looksLikeJobTitleList($nicheLine)) {
                return Str::limit($nicheLine, 160, '');
            }
        }

        foreach (['who_we_sell_to', 'industry', 'search_query', 'customers'] as $key) {
            $value = Arr::get($icp, $key, '');
            if (is_array($value)) {
                $value = implode(' ', array_map(fn ($v) => (string) $v, array_slice($value, 0, 4)));
            }
            $value = trim((string) $value);
            if ($value !== '' && ! $this->looksLikeJobTitleList($value) && ! $this->looksLikeSellerPitch($value)) {
                return Str::limit($value, 160, '');
            }
        }

        $audience = trim((string) ($payload['audience'] ?? $payload['icp_notes'] ?? ''));
        if (preg_match('/\bAND\b\s+(.+)/i', $audience, $match)) {
            $afterTitles = trim(preg_replace('/\s+AND\s+/i', ' ', (string) $match[1]) ?? '');
            $afterTitles = trim(preg_replace('/\s*·.+$/u', '', $afterTitles) ?? '');
            if ($afterTitles !== '' && ! $this->looksLikeJobTitleList($afterTitles) && ! $this->looksLikeSellerPitch($afterTitles)) {
                return Str::limit($afterTitles, 160, '');
            }
        }

        $hints = trim((string) ($payload['goal'] ?? ''));
        if ($hints !== '' && ! $this->looksLikeSellerPitch($hints)) {
            return Str::limit($hints, 160, '');
        }

        $who = trim((string) Arr::get($icp, 'who_we_sell_to', ''));

        return $who !== '' ? Str::limit($who, 160, '') : ($hints !== '' ? Str::limit($hints, 160, '') : 'b2b founders');
    }

    public function looksLikeSellerPitch(string $text): bool
    {
        $lower = Str::lower($text);

        return (bool) preg_match(
            '/\b(we (sell|build|offer|provide)|our (product|platform|software|agency)|custom software|ai[- ]powered solutions|transform business operations)\b/i',
            $lower
        );
    }

    public function looksLikeJobTitleList(string $text): bool
    {
        return (bool) preg_match(
            '/\b(chief|officer|director|manager|founder|owner|vp|vice president|head of)\b/i',
            $text,
        ) && (bool) preg_match('/\bOR\b|,/', $text);
    }

    public function isSendChannel(string $channel): bool
    {
        return in_array($channel, ['linkedin', 'instagram', 'email', 'whatsapp', 'telegram', 'twitter'], true);
    }

    /**
     * Default list store when the discovery row forgot list_src.
     * Instagram/X people land in CSV imports; LinkedIn people land in SN.
     * Email/WhatsApp/Telegram usually reuse whichever list already has those contacts.
     */
    public function defaultListSrc(string $channel): string
    {
        return in_array($channel, ['instagram', 'twitter'], true) ? 'csv' : 'sn';
    }

    /**
     * @return list<string>
     */
    public function conversationSequence(string $channel, bool $firstDegree = false): array
    {
        $opener = match ($channel) {
            'instagram' => 'Instagram DM — personalized after research, no pitch',
            'whatsapp' => 'WhatsApp — personalized after research, no pitch',
            'telegram' => 'Telegram — personalized after research, no pitch',
            'twitter' => 'X DM — personalized after research, no pitch',
            'email' => 'Email — research their site, personalized detailed note with soft value pitch',
            default => $firstDegree
                ? 'LinkedIn message — personalized after research, no invite'
                : 'Send Invite (empty note)',
        };

        if ($channel === 'linkedin' && ! $firstDegree) {
            return [
                $opener,
                'After acceptance',
                'First LinkedIn message — personalized after research',
                'Wait 4 days',
                'Light follow-up if no reply',
                'Pause on reply — handle in inbox',
            ];
        }

        return [
            $opener,
            'Wait 4 days',
            'Light follow-up if no reply',
            'Pause on reply — handle in inbox',
        ];
    }
}

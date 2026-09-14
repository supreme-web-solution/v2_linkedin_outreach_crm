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
        // Prefer THIS TURN's audience/goal when it already names a buyer niche (e.g. "US SaaS founders").
        foreach (['audience', 'goal', 'icp_notes'] as $key) {
            $fromTurn = $this->compressBuyerKeyword((string) ($payload[$key] ?? ''));
            if ($fromTurn !== '') {
                return $fromTurn;
            }
        }

        // Prefer buyer niches / who-we-sell-to over seller pitch (avoids celebrity keyword noise).
        $niches = Arr::get($icp, 'niches', []);
        if (is_array($niches) && $niches !== []) {
            $nicheLine = $this->compressBuyerKeyword(implode(' ', array_map(fn ($v) => (string) $v, array_slice($niches, 0, 4))));
            if ($nicheLine !== '') {
                return $nicheLine;
            }
        }

        foreach (['search_query', 'industry', 'customers', 'who_we_sell_to'] as $key) {
            $value = Arr::get($icp, $key, '');
            if (is_array($value)) {
                $value = implode(' ', array_map(fn ($v) => (string) $v, array_slice($value, 0, 4)));
            }
            $compressed = $this->compressBuyerKeyword((string) $value);
            if ($compressed !== '') {
                return $compressed;
            }
        }

        return 'b2b founders';
    }

    /**
     * Mindcase needs short buyer keywords — never ICP essays or seller pitch paragraphs.
     */
    public function compressBuyerKeyword(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if ($text === '') {
            return '';
        }

        if ($this->looksLikeSellerPitch($text) || $this->looksLikeJobTitleList($text)) {
            return '';
        }

        // Strip meeting/campaign verbs so "Book 20 meetings with US SaaS founders" → niche words.
        $text = trim(preg_replace(
            '/\b(book|get|find|schedule|map out|reach out|outreach|campaign|this month|this quarter|\d+\s*(meetings?|demos?|prospects?|leads?|customers?|people)?)\b/i',
            ' ',
            $text,
        ) ?? '');
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        $text = trim(preg_replace('/^(with|for|to|about|among|across)\s+/i', '', $text) ?? '');

        if ($text === '' || $this->looksLikeSellerPitch($text)) {
            return '';
        }

        // Reject long ICP paragraphs (who_we_sell_to dumps).
        if (strlen($text) > 90 || substr_count($text, ' ') > 10) {
            $words = preg_split('/\s+/', $text) ?: [];
            $keep = [];
            foreach ($words as $word) {
                $w = Str::lower(trim($word, " \t.,;:"));
                if (strlen($w) < 3) {
                    continue;
                }
                if (in_array($w, ['with', 'that', 'this', 'from', 'their', 'your', 'need', 'needs', 'likely', 'building', 'growing', 'businesses', 'application', 'digital', 'product', 'tailored', 'software', 'automation'], true)) {
                    continue;
                }
                $keep[] = $w;
                if (count($keep) >= 5) {
                    break;
                }
            }
            $text = implode(' ', $keep);
        }

        $text = trim($text);
        if ($text === '' || strlen($text) < 3) {
            return '';
        }

        return Str::limit($text, 80, '');
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

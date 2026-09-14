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
     * Domain-agnostic Instagram search keyword.
     * Uses THIS TURN + this workspace's ICP — never a hardcoded industry list.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $icp
     */
    public function instagramKeyword(array $payload, array $icp = []): string
    {
        $candidates = $this->instagramKeywordCandidates($payload, $icp);

        return $candidates[0] ?? 'business owners';
    }

    /**
     * Ordered keyword attempts for Mindcase — rotate on underfill instead of
     * retrying the same garbled phrase five times.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $icp
     * @return list<string>
     */
    public function instagramKeywordCandidates(array $payload, array $icp = []): array
    {
        $out = [];
        $push = function (string $raw) use (&$out): void {
            $kw = $this->compressBuyerKeyword($raw);
            if ($kw === '') {
                return;
            }
            $lower = Str::lower($kw);
            foreach ($out as $existing) {
                if (Str::lower($existing) === $lower) {
                    return;
                }
            }
            $out[] = $kw;
        };

        foreach (['audience', 'goal', 'icp_notes'] as $key) {
            $push((string) ($payload[$key] ?? ''));
        }

        $niches = Arr::get($icp, 'niches', []);
        if (is_array($niches)) {
            foreach (array_slice($niches, 0, 6) as $niche) {
                $push((string) $niche);
            }
        }

        $industry = trim((string) Arr::get($icp, 'industry', ''));
        $decisionMaker = trim((string) Arr::get($icp, 'decision_maker', ''));
        if ($industry !== '' && ! preg_match('/^(global|various|general)$/i', $industry)) {
            $role = $this->shortRoleWord($decisionMaker) ?? 'owners';
            $push($industry.' '.$role);
        }

        // Buyer-facing ICP fields before product-shaped search_query.
        foreach (['who_we_sell_to', 'customers', 'search_query'] as $key) {
            $value = Arr::get($icp, $key, '');
            if (is_array($value)) {
                $value = implode(' ', array_map(fn ($v) => (string) $v, array_slice($value, 0, 4)));
            }
            $push((string) $value);
        }

        if ($out === []) {
            $role = $this->shortRoleWord($decisionMaker);
            $out[] = $role !== null ? $role : 'business owners';
        }

        return array_values($out);
    }

    /**
     * Mindcase needs short buyer keywords — structural cleanup only, no industry hardcoding.
     */
    public function compressBuyerKeyword(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if ($text === '') {
            return '';
        }

        // Strip channel locks / campaign chrome (domain-agnostic).
        $text = trim(preg_replace(
            '/^\s*(instagram|linkedin|email|whatsapp|telegram|twitter|x)\s*only\s*[:\-]?\s*/i',
            '',
            $text,
        ) ?? '');
        $text = trim(preg_replace(
            '/\b(instagram|linkedin|email|whatsapp|telegram|twitter)\s*only\b/i',
            ' ',
            $text,
        ) ?? '');
        $text = trim(preg_replace(
            '/\b(book|booking|get|find|schedule|map out|reach out|outreach|campaign|this month|this quarter|ideal|my|me|\d+\s*(meetings?|bookings?|demos?|prospects?|leads?|customers?|people)?)\b/i',
            ' ',
            $text,
        ) ?? '');
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        $text = trim(preg_replace('/^(with|for|to|from|about|among|across)\s+/i', '', $text) ?? '');
        $text = trim(preg_replace('/\b(with|for|to|from|about)\b/i', ' ', $text) ?? '');
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        $text = trim(preg_replace('/\bonly\b/i', ' ', $text) ?? '');
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');

        if ($text === ''
            || $this->looksLikeSellerPitch($text)
            || $this->looksLikeJobTitleList($text)
            || $this->looksLikeGenericAudienceAsk($text)
            || preg_match('/^(instagram|linkedin|email|whatsapp|telegram|twitter)$/i', $text)
        ) {
            return '';
        }

        // Any niche noun + decision-maker role (works for any vertical).
        if (preg_match(
            '/\b([a-z][a-z0-9&\-]{2,40})\s+(founders?|owners?|ceos?|ctos?|cmos?|directors?|managers?|operators?|partners?)\b/i',
            $text,
            $m,
        )) {
            $noun = Str::lower($m[1]);
            $role = Str::lower($m[2]);
            if (! $this->isFillerNoun($noun)) {
                return Str::limit($noun.' '.$role, 80, '');
            }
        }

        // Long ICP essays → keep a few content words; drop structural filler.
        if (strlen($text) > 90 || substr_count($text, ' ') > 10) {
            $words = preg_split('/\s+/', $text) ?: [];
            $keep = [];
            foreach ($words as $word) {
                $w = Str::lower(trim($word, " \t.,;:"));
                if (strlen($w) < 3 || $this->isFillerNoun($w)) {
                    continue;
                }
                $keep[] = $w;
                if (count($keep) >= 4) {
                    break;
                }
            }
            $text = implode(' ', $keep);
        }

        $text = trim($text);
        $text = trim(preg_replace('/\b(and|or|with|for|to|about)$/i', '', $text) ?? '');
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if ($text === '' || strlen($text) < 3) {
            return '';
        }
        if (preg_match('/\b(needing|looking|established companies|new needing)\b/i', $text)) {
            return '';
        }
        if ($this->looksLikeGenericAudienceAsk($text)) {
            return '';
        }

        return Str::limit($text, 80, '');
    }

    public function looksLikeGenericAudienceAsk(string $text): bool
    {
        $lower = Str::lower(trim($text));

        if (preg_match(
            '/\b[a-z][a-z0-9&\-]{2,40}\s+(founders?|owners?|ceos?|ctos?|directors?|managers?)\b/i',
            $lower,
        )) {
            return false;
        }

        return (bool) preg_match(
            '/\b(ideal customers?|my customers?|my clients?|my prospects?|customers? only|people to book)\b/i',
            $lower
        ) || (bool) preg_match('/^(customers?|clients?|prospects?|leads?|me with customers?|with customers?)$/i', $lower)
            || (bool) preg_match('/^(me\s+)?(with\s+)?customers?$/i', $lower);
    }

    public function looksLikeSellerPitch(string $text): bool
    {
        $lower = Str::lower($text);

        return (bool) preg_match(
            '/\b(we (sell|build|offer|provide)|our (product|platform|software|agency|company)|ai[- ]powered solutions|transform business operations|pay (us|the company) to)\b/i',
            $lower
        );
    }

    public function looksLikeJobTitleList(string $text): bool
    {
        $titleHits = preg_match_all(
            '/\b(chief|officer|director|manager|founder|owner|ceo|cto|cmo|vp|vice president|head of)\b/i',
            $text,
        );

        return $titleHits >= 2
            && (bool) preg_match('/\bOR\b|,|\bor\b/i', $text);
    }

    private function isFillerNoun(string $word): bool
    {
        return in_array($word, [
            'with', 'that', 'this', 'from', 'their', 'your', 'need', 'needs', 'needing', 'needed',
            'likely', 'building', 'growing', 'businesses', 'application', 'digital', 'product',
            'tailored', 'software', 'automation', 'new', 'established', 'companies', 'company',
            'global', 'across', 'various', 'industries', 'industry', 'startups', 'startup',
            'enterprise', 'enterprises', 'operations', 'solutions', 'services', 'customers',
            'customer', 'clients', 'client', 'people', 'business', 'only', 'instagram',
            'linkedin', 'whatsapp', 'telegram', 'twitter',
        ], true);
    }

    private function shortRoleWord(string $decisionMaker): ?string
    {
        if (preg_match('/\b(founders?|owners?|ceos?|ctos?|directors?|managers?)\b/i', $decisionMaker, $m)) {
            return Str::lower($m[1]);
        }

        return null;
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

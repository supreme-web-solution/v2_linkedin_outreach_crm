<?php

namespace App\V2\Ai\Services;

use Illuminate\Support\Str;

class PlanContentService
{
    public function __construct(
        private readonly LaravelAiJsonService $jsonAi,
    ) {}

    /**
     * Official workspace ICP for discovery, strategy, and outreach.
     * Built from this company's materials and stated goal — not a generic persona.
     *
     * @param  array<string, mixed>  $context  owner_goal, preferred_channels, website_title
     * @return array<string, mixed>
     */
    public function buildIcp(
        string $offer,
        ?string $website,
        array $competitors,
        string $geography,
        ?string $notes,
        array $customers = [],
        array $context = [],
    ): array {
        $base = $this->fallbackIcp($offer, $competitors, $geography, $website, $notes, $customers, $context);

        if ($this->jsonAi->isAvailable()) {
            try {
                $payload = $this->jsonAi->generate(
                    $this->icpSystemPrompt(),
                    json_encode([
                        'task' => 'Build this company\'s official Ideal Customer Profile for real outbound.',
                        'what_they_sell' => $offer,
                        'website' => $website,
                        'website_title' => $context['website_title'] ?? null,
                        'owner_goal' => $context['owner_goal'] ?? null,
                        'preferred_channels' => $context['preferred_channels'] ?? [],
                        'example_customers' => $customers,
                        'competitors' => $competitors,
                        'stated_geography' => $geography,
                        // Long owner pastes: keep enough signal for niches/buyers; avoid provider timeouts.
                        'source_notes' => Str::limit((string) ($notes ?? ''), 12000, ''),
                        'required_json_keys' => [
                            'who_we_sell_to',
                            'primary_outcome',
                            'industry',
                            'niches',
                            'company_profile',
                            'geography',
                            'decision_makers',
                            'economic_buyer',
                            'day_to_day_champion',
                            'pains',
                            'jobs_to_be_done',
                            'buying_triggers',
                            'disqualifiers',
                            'search_query',
                            'search_titles',
                            'lookalikes',
                            'outreach_angle',
                            'do_not_say',
                            'channels_fit',
                            'summary',
                            'decision_maker',
                            'likely_pain',
                            'company_size',
                        ],
                    ], JSON_THROW_ON_ERROR),
                    1400,
                );

                if ($payload !== []) {
                    return $this->normalizeIcp(array_merge($base, $payload, [
                        'competitors' => $competitors,
                        'customers' => $customers,
                        'website' => $website,
                        'notes' => $notes,
                        'owner_goal' => $context['owner_goal'] ?? null,
                        'preferred_channels' => $context['preferred_channels'] ?? [],
                    ]));
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $this->normalizeIcp($base);
    }

    public function summarizeBusiness(string $rawText): string
    {
        $rawText = trim($rawText);
        if ($rawText === '') {
            return '';
        }

        if ($this->jsonAi->isAvailable()) {
            try {
                $payload = $this->jsonAi->generate(
                    'Write a precise 2–3 sentence description of what this company sells and who pays. '
                    .'Use only the source. Do not invent SaaS, founders, or a market they did not describe. Return JSON only.',
                    json_encode([
                        'source' => Str::limit($rawText, 6000, ''),
                        'schema' => ['summary' => 'string'],
                    ], JSON_THROW_ON_ERROR),
                    400,
                );
                $summary = trim((string) ($payload['summary'] ?? ''));
                if ($summary !== '') {
                    return Str::limit($summary, 500, '');
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $paragraphs = preg_split('/\n{2,}/', $rawText) ?: [];
        $first = trim((string) ($paragraphs[0] ?? $rawText));

        return Str::limit(preg_replace('/\s+/', ' ', $first) ?? $first, 400);
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    public function enrichStrategy(array $base): array
    {
        if (! $this->jsonAi->isAvailable()) {
            return $base;
        }

        try {
            $payload = $this->jsonAi->generate(
                'You are Soci, SociFusion AI Sales Command Center. Return JSON only. '
                .'Default when unspecified: LinkedIn + Email. When the user asks for Instagram, Telegram, or WhatsApp, make that channel primary in preferred_channels. '
                .'Prefer pause_on_reply for inbound replies (Soci handles them in inbox). '
                .'LinkedIn campaigns should use invite_accepted before DMs.',
                json_encode([
                    'task' => 'Refine a sales execution strategy plan',
                    'plan' => $base,
                    'schema' => [
                        'icp_notes' => 'string',
                        'preferred_channels' => 'string',
                        'target_count' => 'integer',
                        'follow_up_days' => 'integer',
                        'pause_on_reply' => 'boolean',
                        'steps' => 'array of short action strings',
                        'risks' => 'array of strings',
                    ],
                ], JSON_THROW_ON_ERROR),
                800,
            );

            if ($payload !== []) {
                return array_merge($base, array_filter([
                    'icp_notes' => $payload['icp_notes'] ?? null,
                    'preferred_channels' => $payload['preferred_channels'] ?? null,
                    'target_count' => isset($payload['target_count']) ? (int) $payload['target_count'] : null,
                    'follow_up_days' => isset($payload['follow_up_days']) ? (int) $payload['follow_up_days'] : null,
                    'pause_on_reply' => array_key_exists('pause_on_reply', $payload)
                        ? (bool) $payload['pause_on_reply']
                        : null,
                    'steps' => $payload['steps'] ?? null,
                    'risks' => $payload['risks'] ?? null,
                ], fn ($v) => $v !== null));
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return $base;
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    public function enrichCampaign(array $base): array
    {
        if (! empty($base['single_channel_only']) && trim((string) ($base['primary_channel'] ?? '')) !== '') {
            return $base;
        }

        if (! $this->jsonAi->isAvailable()) {
            return $base;
        }

        try {
            $payload = $this->jsonAi->generate(
                'You are Soci, SociFusion outreach architect. Return JSON only. '
                .'Design the smartest sequence for this goal — choose channels and nodes deliberately. '
                .'LinkedIn: empty send_invite, then After acceptance / invite_accepted before any DM; never a second invite. '
                .'Put follow-up DMs after acceptance; put email/WhatsApp on not-accepted when those channels are used. '
                .'Default reply handling is pause_on_reply (sequence stops; Soci replies in inbox) — include a sequence line like "Pause on reply — handle in inbox". '
                .'Only add has_replied / no_reply / message_replied steps when the sequence must BRANCH on silence vs reply. '
                .'Do not invent an Soci-reply action step. Prefer short high-converting sequences over long ones.',
                json_encode([
                    'task' => 'Refine a multi-channel outreach campaign plan with wise node choices',
                    'plan' => $base,
                    'available_conditions' => [
                        'linkedin' => ['invite_accepted', 'has_replied', 'no_reply'],
                        'email' => ['email_replied', 'no_reply', 'email_opened', 'email_bounced'],
                        'whatsapp_instagram_telegram' => ['message_replied', 'no_reply'],
                    ],
                    'schema' => [
                        'audience' => 'string',
                        'preferred_channels' => 'string',
                        'channels' => 'string',
                        'target_count' => 'integer',
                        'follow_up_days' => 'integer',
                        'pause_on_reply' => 'boolean (default true)',
                        'sequence' => 'array of step labels including After acceptance / Pause on reply when appropriate',
                        'steps' => 'array of execution steps',
                    ],
                ], JSON_THROW_ON_ERROR),
                1100,
            );

            if ($payload !== []) {
                return array_merge($base, array_filter([
                    'audience' => $payload['audience'] ?? null,
                    'preferred_channels' => $payload['preferred_channels'] ?? null,
                    'channels' => $payload['channels'] ?? null,
                    'target_count' => isset($payload['target_count']) ? (int) $payload['target_count'] : null,
                    'follow_up_days' => isset($payload['follow_up_days']) ? (int) $payload['follow_up_days'] : null,
                    'pause_on_reply' => array_key_exists('pause_on_reply', $payload)
                        ? (bool) $payload['pause_on_reply']
                        : null,
                    'sequence' => $payload['sequence'] ?? null,
                    'steps' => $payload['steps'] ?? null,
                ], fn ($v) => $v !== null));
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return $base;
    }

    /**
     * @return array<string, mixed>
     */
    private function fallbackIcp(
        string $offer,
        array $competitors,
        string $geography,
        ?string $website = null,
        ?string $notes = null,
        array $customers = [],
        array $context = [],
    ): array {
        $lookalike = $customers !== []
            ? 'Companies similar to '.implode(', ', array_slice($customers, 0, 3))
            : null;
        $offerLine = trim($offer) !== '' ? trim($offer) : 'this offer';
        $search = Str::limit($offerLine, 120, '');

        return [
            'who_we_sell_to' => $lookalike
                ? $lookalike.' that would buy '.$offerLine
                : 'Buyers described in the owner materials for '.$offerLine,
            'primary_outcome' => trim((string) ($context['owner_goal'] ?? '')) ?: 'Book a qualified conversation with the right buyer',
            'industry' => Str::limit($offerLine, 80, ''),
            'niches' => [],
            'company_profile' => 'As described in the owner materials',
            'geography' => $geography !== '' ? $geography : 'Global',
            'decision_makers' => [],
            'economic_buyer' => null,
            'day_to_day_champion' => null,
            'pains' => array_values(array_filter([Str::limit((string) $notes, 160, '')])),
            'jobs_to_be_done' => [],
            'buying_triggers' => [],
            'disqualifiers' => [],
            'search_query' => $search,
            'search_titles' => [],
            'lookalikes' => $lookalike,
            'lookalike_of_customers' => $lookalike,
            'outreach_angle' => 'Open with their situation, not a product pitch.',
            'do_not_say' => [],
            'channels_fit' => [],
            'summary' => $lookalike
                ? $lookalike.' that need '.$offerLine
                : $offerLine,
            'decision_maker' => $search,
            'likely_pain' => Str::limit(trim((string) $notes) !== '' ? (string) $notes : $offerLine, 160, ''),
            'company_size' => null,
            'competitors' => $competitors,
            'customers' => $customers,
            'website' => $website,
            'notes' => $notes,
            'owner_goal' => $context['owner_goal'] ?? null,
            'preferred_channels' => $context['preferred_channels'] ?? [],
            'needs_review' => true,
        ];
    }

    private function icpSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a senior sales strategist writing this company's official Ideal Customer Profile for outbound.

This ICP will steer discovery, messaging, and campaigns. If it is vague, every later step fails.

Rules:
- Use only evidence in what they sell, website, notes, example customers, competitors, and the owner's stated goal.
- Write as a human building a real company ICP — specific enough that a teammate could search and message tomorrow without asking again.
- Mirror THEIR market. If they sell to clinics, farms, schools, contractors, or brands, the ICP is that world. Never default to SaaS, founders, or "B2B services" unless their materials say so.
- Do not invent titles, industries, geos, or pains that are not implied.
- When source_notes is a long ICP essay: DISTILL. Do not copy paragraphs into who_we_sell_to or summary.
- niches must be 4–10 short buyer niches usable as Instagram/LinkedIn search keywords (noun + role when possible), taken from THEIR priority industries and buyers — e.g. "saas founders", "ecommerce operators", "agency owners". Never one long sentence.
- who_we_sell_to is 1–2 sharp sentences about the buyer company, not a service catalog.
- search_query must be executable people-search language for THIS audience (who + what they care about + geo if known). Not a slogan. Not a pitch.
- search_titles are 1–4 job titles as they would appear on a profile, taken from this ICP — not a global title list.
- decision_makers is an array of {title, why}. Also set decision_maker to those titles joined by commas (legacy field).
- likely_pain is the single sharpest pain (legacy field). pains is the fuller list.
- outreach_angle is the first-message thesis (one diagnostic idea). No product dump.
- channels_fit explains how LinkedIn / Instagram / Email / WhatsApp help THIS ICP, using preferred_channels when provided.
- primary_outcome is the owner's real win (booked call, project inquiry, webinar, etc.).
- Return JSON only with every required key.
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $icp
     * @return array<string, mixed>
     */
    private function normalizeIcp(array $icp): array
    {
        foreach (['niches', 'pains', 'jobs_to_be_done', 'buying_triggers', 'disqualifiers', 'search_titles', 'do_not_say', 'customers', 'competitors'] as $listKey) {
            $icp[$listKey] = $this->stringList($icp[$listKey] ?? []);
        }

        $makers = $icp['decision_makers'] ?? [];
        if (! is_array($makers)) {
            $makers = [];
        }
        $normalizedMakers = [];
        foreach ($makers as $maker) {
            if (is_string($maker) && trim($maker) !== '') {
                $normalizedMakers[] = ['title' => trim($maker), 'why' => ''];
            } elseif (is_array($maker) && trim((string) ($maker['title'] ?? '')) !== '') {
                $normalizedMakers[] = [
                    'title' => trim((string) $maker['title']),
                    'why' => trim((string) ($maker['why'] ?? '')),
                ];
            }
        }
        $icp['decision_makers'] = $normalizedMakers;

        if (trim((string) ($icp['decision_maker'] ?? '')) === '' && $normalizedMakers !== []) {
            $icp['decision_maker'] = implode(', ', array_column($normalizedMakers, 'title'));
        }

        if (trim((string) ($icp['likely_pain'] ?? '')) === '' && ($icp['pains'][0] ?? null)) {
            $icp['likely_pain'] = $icp['pains'][0];
        }

        if (trim((string) ($icp['search_query'] ?? '')) === '') {
            $icp['search_query'] = trim(implode(' ', array_filter([
                (string) ($icp['decision_maker'] ?? ''),
                (string) ($icp['industry'] ?? ''),
                (string) ($icp['geography'] ?? ''),
            ])));
        }

        foreach (['who_we_sell_to', 'summary', 'search_query', 'outreach_angle', 'industry', 'geography', 'primary_outcome'] as $textKey) {
            if (isset($icp[$textKey]) && is_string($icp[$textKey])) {
                $icp[$textKey] = trim($icp[$textKey]);
            }
        }

        return $icp;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return is_string($value) && trim($value) !== '' ? [trim($value)] : [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return array_values($out);
    }
}

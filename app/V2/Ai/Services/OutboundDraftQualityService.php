<?php

namespace App\V2\Ai\Services;

use App\Ai\Agents\OutboundDraftQualityAgent;
use Illuminate\Support\Str;
use Throwable;

/**
 * Research + Laravel AI groundedness checks for outbound drafts (domain-agnostic).
 */
class OutboundDraftQualityService
{
    public function __construct(
        private readonly AiProviderChain $providers,
        private readonly OutboundMessageComposerService $composer,
    ) {}

    /**
     * When a research URL was provided, refuse to treat the turn as researched if notes are thin.
     */
    public function researchGateAllowsDraft(string $researchUrl, string $researchNotes): bool
    {
        if (trim($researchUrl) === '') {
            return true;
        }

        return $this->composer->researchIsSubstantial($researchNotes);
    }

    /**
     * @return array{
     *     pass:bool,
     *     score:float,
     *     grounded:bool,
     *     not_generic:bool,
     *     has_clear_cta:bool,
     *     invents_facts:bool,
     *     research_claimed_but_missing:bool,
     *     evidence_used:list<string>,
     *     issues:list<string>,
     *     summary:string,
     *     source:string
     * }
     */
    public function evaluate(
        string $channel,
        string $draft,
        string $researchNotes,
        string $ownerBrief = '',
        ?string $researchUrl = null,
    ): array {
        $draft = trim($draft);
        $fallback = [
            'pass' => $draft !== '',
            'score' => $draft !== '' ? 0.55 : 0.0,
            'grounded' => true,
            'not_generic' => true,
            'has_clear_cta' => true,
            'invents_facts' => false,
            'research_claimed_but_missing' => false,
            'evidence_used' => [],
            'issues' => $draft === '' ? ['Draft is empty.'] : [],
            'summary' => $draft === '' ? 'Empty draft' : 'Quality judge unavailable — structural checks only',
            'source' => 'fallback',
        ];

        if ($draft === '') {
            return $fallback;
        }

        $structural = \App\V2\Ai\Support\RecipientFacingCopyGuard::problems($draft);
        if ($structural !== []) {
            return array_merge($fallback, [
                'pass' => false,
                'score' => 0.2,
                'issues' => $structural,
                'summary' => 'Failed structural recipient-facing checks',
                'source' => 'copy_guard',
            ]);
        }

        $chain = $this->providers->forAgent();
        if ($chain === []) {
            // Without LLM: if URL research was requested and substantial, require some overlap heuristically via length only.
            if ($researchUrl && ! $this->composer->researchIsSubstantial($researchNotes)) {
                return array_merge($fallback, [
                    'pass' => false,
                    'score' => 0.25,
                    'grounded' => false,
                    'research_claimed_but_missing' => true,
                    'issues' => ['Research URL was provided but readable research is too thin to personalize safely.'],
                    'summary' => 'Thin research',
                    'source' => 'research_gate',
                ]);
            }

            return $fallback;
        }

        try {
            $response = (new OutboundDraftQualityAgent)->prompt(
                json_encode([
                    'channel' => $channel,
                    'draft' => Str::limit($draft, 2500),
                    'recipient_research' => $researchNotes !== '' ? Str::limit($researchNotes, 3500) : null,
                    'research_url' => $researchUrl,
                    'owner_brief' => $ownerBrief !== '' ? Str::limit($ownerBrief, 800) : null,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                provider: $chain,
            );

            $raw = is_array($response->structured ?? null) ? $response->structured : [];
            if ($raw === []) {
                return $fallback;
            }

            $issues = array_values(array_filter(array_map(
                fn ($i) => is_string($i) ? trim($i) : '',
                is_array($raw['issues'] ?? null) ? $raw['issues'] : [],
            )));
            $evidence = array_values(array_filter(array_map(
                fn ($i) => is_string($i) ? trim($i) : '',
                is_array($raw['evidence_used'] ?? null) ? $raw['evidence_used'] : [],
            )));

            return [
                'pass' => (bool) ($raw['pass'] ?? false),
                'score' => max(0.0, min(1.0, (float) ($raw['score'] ?? 0))),
                'grounded' => (bool) ($raw['grounded'] ?? false),
                'not_generic' => (bool) ($raw['not_generic'] ?? false),
                'has_clear_cta' => (bool) ($raw['has_clear_cta'] ?? false),
                'invents_facts' => (bool) ($raw['invents_facts'] ?? false),
                'research_claimed_but_missing' => (bool) ($raw['research_claimed_but_missing'] ?? false),
                'evidence_used' => $evidence,
                'issues' => $issues,
                'summary' => trim((string) ($raw['summary'] ?? 'Quality evaluated')),
                'source' => 'laravel_ai',
            ];
        } catch (Throwable $e) {
            report($e);

            return $fallback;
        }
    }

    /**
     * Compact bullets for Review & Launch cards (facts from research notes).
     *
     * @return list<string>
     */
    public function researchFactBullets(string $researchNotes, int $limit = 3): array
    {
        $notes = trim($researchNotes);
        if ($notes === '') {
            return [];
        }

        $chunks = preg_split('/\n+/', $notes) ?: [];
        $bullets = [];
        foreach ($chunks as $chunk) {
            $line = trim(preg_replace('/\s+/', ' ', $chunk) ?? '');
            if ($line === '' || preg_match('/^(URL|Title|AI research notes):/i', $line)) {
                continue;
            }
            $bullets[] = Str::limit($line, 140, '…');
            if (count($bullets) >= $limit) {
                break;
            }
        }

        if ($bullets === [] && strlen($notes) > 40) {
            $bullets[] = Str::limit(preg_replace('/\s+/', ' ', $notes) ?? $notes, 160, '…');
        }

        return $bullets;
    }
}

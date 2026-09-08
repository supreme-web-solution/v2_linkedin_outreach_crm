<?php

namespace App\V2\Ai\Services;

use App\Models\Audience;
use App\Models\AudienceList;
use App\Models\User;
use App\V2\Ai\Support\PlanLeadList;
use App\V2\Services\LeadListService;
use Illuminate\Support\Str;

class DiscoverProspectsService
{
    /** Minimum score to treat a saved list as an intentional match (not engagers fallback). */
    public const STRONG_MATCH_SCORE = 10;

    public function __construct(
        private readonly LeadListService $leadLists,
        private readonly ProspectAudienceResolverService $audienceResolver,
        private readonly LinkedInAudienceBuilderService $linkedInAudience,
    ) {}

    /**
     * Unified discovery: existing lists + competitor harvest audiences + recommended next step.
     *
     * @return array<string, mixed>
     */
    public function discover(
        User $user,
        string $query,
        ?string $competitors = null,
        int $limit = 10,
        ?int $targetCount = null,
        bool $preferFresh = false,
        ?string $geography = null,
        ?string $networkDegree = null,
        ?string $title = null,
        ?string $company = null,
        ?bool $openLink = null,
    ): array {
        $limit = max(1, min(20, $limit));
        $query = trim($query);
        $competitorNames = array_values(array_filter(array_map('trim', explode(',', (string) $competitors))));
        $targetCount = $targetCount !== null ? max(10, min(500, $targetCount)) : null;

        // Any explicit net-new size (or prefer_fresh) → LinkedIn search + SAVE, do not reuse engagers lists.
        $forceFresh = $preferFresh || $targetCount !== null;

        $lists = $this->matchLeadLists($user, $query, $limit, includeWeakFallback: ! $forceFresh);
        $competitorAudiences = $forceFresh
            ? []
            : $this->competitorAudiences($user, $competitorNames, $limit);
        $merged = collect($lists)->concat($competitorAudiences)->unique(fn (array $row) => ($row['list_src'] ?? '').':'.($row['list_hash'] ?? ''))->values();

        $best = $merged->sortByDesc('match_score')->first();
        $strongMatch = $best && (int) ($best['match_score'] ?? 0) >= self::STRONG_MATCH_SCORE
            ? $best
            : null;

        $planProbe = array_filter([
            'goal' => $query,
            'icp_notes' => $query,
            'audience' => $query,
            'target_count' => $targetCount ?? 100,
            'prefer_fresh_audience' => $forceFresh,
            'geography' => $geography,
            'network_degree' => $networkDegree,
            'title' => $title,
            'current_company' => $company,
            'open_link' => $openLink,
        ], fn ($v) => $v !== null && $v !== '');

        // Only attach a saved list before search when it is a strong name match AND user did not ask for fresh N.
        if (! $forceFresh && $strongMatch) {
            $planProbe = PlanLeadList::merge(
                $planProbe,
                (string) $strongMatch['list_hash'],
                (string) $strongMatch['list_src'],
                (string) ($strongMatch['list_name'] ?? 'Matched list'),
            );
        }

        $resolved = $forceFresh
            ? null
            : $this->audienceResolver->resolve($user, $planProbe, strict: true);

        if ($resolved !== null && (int) ($resolved['match_score'] ?? 0) < self::STRONG_MATCH_SCORE) {
            $resolved = null;
        }

        $autoSourced = null;
        $shouldAutoSearch = $forceFresh || $resolved === null;

        if ($shouldAutoSearch) {
            $searchPlan = $planProbe;
            unset($searchPlan['list_hash'], $searchPlan['list_src'], $searchPlan['list_name'], $searchPlan['lead_list_id'], $searchPlan['lead_list_src']);

            $autoSourced = $this->linkedInAudience->tryBuildFromPlan(
                $user,
                (int) ($user->current_organization_id ?? 0),
                $searchPlan,
            );

            if ($autoSourced) {
                $planProbe = PlanLeadList::merge(
                    $planProbe,
                    $autoSourced['list_hash'],
                    $autoSourced['list_src'],
                    $autoSourced['list_name'],
                );
                if (! empty($autoSourced['network_depths'])) {
                    $planProbe['network_depths'] = $autoSourced['network_depths'];
                }
                if (! empty($autoSourced['first_degree_only'])) {
                    $planProbe['first_degree_only'] = true;
                }
                if (! empty($autoSourced['search_filters'])) {
                    $planProbe['search_filters'] = $autoSourced['search_filters'];
                }
                $resolved = $autoSourced;
                $merged = $merged->prepend(array_merge($autoSourced, [
                    'origin' => 'linkedin_search',
                    'note' => 'Fetched from LinkedIn and saved'
                        .($targetCount ? " (target {$targetCount})" : ''),
                    'match_score' => 100,
                ]));
            }
        }

        $totalLeads = (int) $merged->sum(fn (array $row) => (int) ($row['total_leads'] ?? 0));

        $nextSteps = [];
        if ($resolved === null && $merged->isEmpty()) {
            $nextSteps = [
                'Connect LinkedIn in SociFusion → Integrations, then ask again — Alex will fetch and save matching profiles.',
                'Or import / build a lead list in SociFusion → Leads',
                'Or share a competitor LinkedIn company URL to harvest engagers',
            ];
        } elseif ($resolved === null) {
            $previewFilters = \App\V2\Ai\Support\IcpSearchFilterParser::fromGoal(
                $query,
                null,
                $targetCount,
            );
            $nextSteps = [
                'LinkedIn search did not return profiles yet.',
                'Prepared filters: keywords="'.($previewFilters['keywords'] ?? '').'"'
                    .' title='.($previewFilters['title'] ?? 'any')
                    .' location='.($previewFilters['location'] ?? 'any'),
                'Alex retries broader variants automatically — ask again, or check LinkedIn under Integrations.',
            ];
        } elseif ($autoSourced !== null) {
            $found = (int) ($resolved['total_leads'] ?? 0);
            $nextSteps = [
                'Fetched and saved audience: '.$resolved['list_name']." ({$found} profiles).",
                'list_hash='.$resolved['list_hash'].' list_src='.$resolved['list_src'],
            ];
            if (! empty($autoSourced['first_degree_only'])) {
                $nextSteps[] = 'Audience is 1st-degree (already connected): draft_campaign_plan with LinkedIn messages only — do NOT use send_invite.';
            } elseif (! empty($autoSourced['network_depths'])) {
                $nextSteps[] = 'Network filter: '.implode(',', $autoSourced['network_depths'])
                    .' (F=1st connected, S=2nd, O=3rd+). Plan invites only when S/O (or mixed) — not for F-only.';
            }
            if ($targetCount !== null && $found < $targetCount) {
                $nextSteps[] = "Found {$found} of ~{$targetCount} requested. Ask Alex to discover again to grow this same saved list (≤100 per run).";
            }
            $nextSteps = array_merge($nextSteps, [
                '• propose_strategy or draft_campaign_plan USING this list_hash (do not swap to an old engagers list)',
                '• prepare_enrichment if emails/phones are missing',
                '• Launch when ready',
            ]);
        } else {
            $nextSteps = [
                'Best saved audience: '.$resolved['list_name'].' ('.$resolved['total_leads'].' leads).',
                '• propose_strategy or draft_campaign_plan with this list attached',
                '• Or pass target_count to fetch NEW LinkedIn profiles instead',
            ];
        }

        $searchFilters = is_array($autoSourced['search_filters'] ?? null) ? $autoSourced['search_filters'] : null;
        $firstDegree = (bool) ($autoSourced['first_degree_only'] ?? false);

        return [
            'query' => $query,
            'lists' => $merged->take($limit)->values()->all(),
            'competitor_audiences' => $competitorAudiences,
            'best_match' => $resolved,
            'total_leads_in_matches' => $totalLeads,
            'ready_for_campaign' => $resolved !== null,
            'auto_sourced' => $autoSourced !== null,
            'fresh_fetch' => $forceFresh,
            'target_count' => $targetCount,
            'search_filters' => $searchFilters,
            'network_depths' => $autoSourced['network_depths'] ?? ($searchFilters['network_depths'] ?? null),
            'first_degree_only' => $firstDegree,
            'campaign_hint' => $firstDegree
                ? '1st-degree list: plan LinkedIn DM sequence only (no send_invite / invite_accepted).'
                : null,
            'next_steps' => $nextSteps,
            'limits' => [
                'note' => 'When target_count / prefer_fresh is set, Alex ALWAYS fetches LinkedIn profiles and saves a new list. Saved engagers lists are only reused on a strong name match when you did not ask for a fresh count.',
                'linkedin_search_cap' => 'Each LinkedIn fetch returns up to ~100 profiles and is saved under Leads. Repeat discover_prospects to grow the same list.',
                'search_params' => 'Pass geography, network_degree (1st|2nd|3rd / F|S|O), title, company, open_link. Unipile classic people search.',
                'competitor_harvest' => 'Optional: prepare_competitor_harvest for engagers from a competitor post/profile.',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function matchLeadLists(User $user, string $query, int $limit, bool $includeWeakFallback = true): array
    {
        $tokens = array_values(array_filter(preg_split('/\s+/', Str::lower($query)) ?: [], fn ($t) => strlen($t) >= 2));
        $lists = $this->leadLists->listsForUser($user->id);

        $matched = $lists
            ->map(function (array $list) use ($tokens, $query) {
                $name = Str::lower((string) $list['list_name']);
                $score = 0;
                if ($query !== '' && str_contains($name, Str::lower($query))) {
                    $score += 10;
                }
                foreach ($tokens as $token) {
                    if (str_contains($name, $token)) {
                        $score += 2;
                    }
                }
                // Size bonus alone must not create a "match" — only boost real name hits.
                if ($score > 0) {
                    $score += min(3, (int) floor(((int) $list['total_leads']) / 200));
                }

                return $score > 0 ? array_merge($list, [
                    'match_score' => $score,
                    'list_hash' => (string) $list['list_id'],
                    'list_src' => (string) $list['src'],
                    'origin' => 'lead_list',
                ]) : null;
            })
            ->filter()
            ->sortByDesc('match_score')
            ->take($limit)
            ->values()
            ->all();

        if ($matched === [] && $includeWeakFallback && $lists->isNotEmpty()) {
            return $lists->sortByDesc('total_leads')->take(min(5, $limit))->values()
                ->map(fn (array $l) => array_merge($l, [
                    'match_score' => 0,
                    'note' => 'No keyword match — largest existing lists (suggestions only)',
                    'list_hash' => (string) $l['list_id'],
                    'list_src' => (string) $l['src'],
                    'origin' => 'lead_list',
                ]))
                ->all();
        }

        return $matched;
    }

    /**
     * @param  list<string>  $competitorNames
     * @return list<array<string, mixed>>
     */
    private function competitorAudiences(User $user, array $competitorNames, int $limit): array
    {
        $audiences = Audience::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get(['id', 'audience_name', 'audience_id', 'source', 'source_meta', 'created_at']);

        return $audiences->map(function (Audience $a) use ($competitorNames) {
            $count = AudienceList::query()->where('audience_id', $a->audience_id)->count();
            $meta = json_decode((string) $a->source_meta, true) ?: [];
            $name = (string) $a->audience_name;
            $hay = Str::lower($name.' '.($meta['company_name'] ?? '').' '.($meta['person_name'] ?? ''));
            $score = 0;

            if (str_contains(Str::lower((string) $a->source), 'competitor')) {
                $score += 3;
            }

            foreach ($competitorNames as $competitor) {
                if ($competitor !== '' && str_contains($hay, Str::lower($competitor))) {
                    $score += 8;
                }
            }

            // Name tokens from competitor names only — no free base score for every engagers list.
            if ($score === 0 || $count === 0) {
                return null;
            }

            return [
                'list_hash' => (string) $a->audience_id,
                'list_src' => 'aud',
                'list_name' => $name,
                'total_leads' => $count,
                'match_score' => $score,
                'origin' => 'competitor_audience',
                'company_name' => $meta['company_name'] ?? $meta['person_name'] ?? null,
            ];
        })->filter()->sortByDesc('match_score')->take($limit)->values()->all();
    }
}

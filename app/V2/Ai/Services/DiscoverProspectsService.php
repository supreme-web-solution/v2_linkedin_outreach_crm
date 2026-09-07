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
    public function discover(User $user, string $query, ?string $competitors = null, int $limit = 10): array
    {
        $limit = max(1, min(20, $limit));
        $query = trim($query);
        $competitorNames = array_values(array_filter(array_map('trim', explode(',', (string) $competitors))));

        $lists = $this->matchLeadLists($user, $query, $limit);
        $competitorAudiences = $this->competitorAudiences($user, $competitorNames, $limit);
        $merged = collect($lists)->concat($competitorAudiences)->unique(fn (array $row) => ($row['list_src'] ?? '').':'.($row['list_hash'] ?? ''))->values();

        $best = $merged->sortByDesc('match_score')->first();
        $planProbe = [
            'goal' => $query,
            'icp_notes' => $query,
            'audience' => $query,
        ];

        if ($best) {
            $planProbe = PlanLeadList::merge(
                $planProbe,
                (string) $best['list_hash'],
                (string) $best['list_src'],
                (string) ($best['list_name'] ?? 'Matched list'),
            );
        }

        $resolved = $this->audienceResolver->resolve($user, $planProbe, strict: true);
        $autoSourced = null;

        if ($resolved === null) {
            $autoSourced = $this->linkedInAudience->tryBuildFromPlan($user, (int) ($user->current_organization_id ?? 0), $planProbe);
            if ($autoSourced) {
                $planProbe = PlanLeadList::merge(
                    $planProbe,
                    $autoSourced['list_hash'],
                    $autoSourced['list_src'],
                    $autoSourced['list_name'],
                );
                $resolved = $autoSourced;
                $merged = $merged->prepend(array_merge($autoSourced, [
                    'origin' => 'linkedin_search',
                    'note' => 'Auto-sourced from LinkedIn search',
                ]));
            }
        }

        $totalLeads = (int) $merged->sum(fn (array $row) => (int) ($row['total_leads'] ?? 0));

        $nextSteps = [];
        if ($resolved === null && $merged->isEmpty()) {
            $nextSteps = [
                'Connect LinkedIn in SociFusion → Integrations, then ask again — Alex will auto-search for matching profiles.',
                'Or import / build a lead list in SociFusion → Leads',
                'Or share a competitor LinkedIn company URL to harvest engagers',
            ];
        } elseif ($resolved === null) {
            $nextSteps = [
                'Lists exist but none strongly match "'.$query.'".',
                'Alex will retry LinkedIn search on Launch — connect LinkedIn if not linked yet.',
                'Or pick the closest list from results and pass list_hash + list_src into your campaign plan',
            ];
        } elseif ($autoSourced !== null) {
            $nextSteps = [
                'Auto-built audience: '.$resolved['list_name'].' ('.$resolved['total_leads'].' profiles from LinkedIn).',
                '• propose_strategy or draft_campaign_plan with this list attached',
                '• prepare_enrichment if emails/phones are missing',
                '• Launch when ready — Autopilot will auto-launch when a list is attached',
            ];
        } else {
            $nextSteps = [
                'Best audience: '.$resolved['list_name'].' ('.$resolved['total_leads'].' leads).',
                '• propose_strategy or draft_campaign_plan with this list attached',
                '• prepare_enrichment if emails/phones are missing',
                '• Launch only when the plan shows the audience list',
            ];
        }

        return [
            'query' => $query,
            'lists' => $merged->take($limit)->values()->all(),
            'competitor_audiences' => $competitorAudiences,
            'best_match' => $resolved,
            'total_leads_in_matches' => $totalLeads,
            'ready_for_campaign' => $resolved !== null,
            'auto_sourced' => $autoSourced !== null,
            'next_steps' => $nextSteps,
            'limits' => [
                'note' => 'Alex checks saved lists first, then auto-searches LinkedIn via your connected account when no list matches.',
                'competitor_harvest' => 'Optional: prepare_competitor_harvest for engagers from a competitor post/profile.',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function matchLeadLists(User $user, string $query, int $limit): array
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
                $score += min(3, (int) floor(((int) $list['total_leads']) / 200));

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

        if ($matched === [] && $lists->isNotEmpty()) {
            return $lists->sortByDesc('total_leads')->take(min(5, $limit))->values()
                ->map(fn (array $l) => array_merge($l, [
                    'match_score' => 0,
                    'note' => 'No keyword match — largest existing lists',
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
            $score = str_contains(Str::lower((string) $a->source), 'competitor') ? 5 : 2;

            foreach ($competitorNames as $competitor) {
                if ($competitor !== '' && str_contains($hay, Str::lower($competitor))) {
                    $score += 8;
                }
            }

            if ($count === 0) {
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

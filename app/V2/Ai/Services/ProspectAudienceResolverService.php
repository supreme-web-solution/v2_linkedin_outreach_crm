<?php

namespace App\V2\Ai\Services;

use App\Models\User;
use App\V2\Ai\Support\PlanLeadList;
use App\V2\Services\LeadListService;
use Illuminate\Support\Str;
use RuntimeException;

class MissingProspectAudienceException extends RuntimeException
{
    /**
     * @param  list<string>  $nextSteps
     */
    public function __construct(
        string $message,
        public readonly array $nextSteps = [],
    ) {
        parent::__construct($message);
    }
}

class ProspectAudienceResolverService
{
    public function __construct(
        private readonly LeadListService $leadLists,
        private readonly LinkedInAudienceBuilderService $linkedInAudience,
    ) {}

    /**
     * @param  array<string, mixed>  $plan
     * @return array{
     *     list_hash: string,
     *     list_src: string,
     *     list_name: string,
     *     total_leads: int,
     *     match_score: int
     * }|null
     */
    public function resolve(User $user, array $plan, bool $strict = true): ?array
    {
        $hash = trim((string) ($plan['list_hash'] ?? $plan['lead_list_id'] ?? ''));
        $src = trim((string) ($plan['list_src'] ?? $plan['lead_list_src'] ?? ''));

        if ($hash !== '' && in_array($src, ['aud', 'sn', 'csv'], true)) {
            $lists = $this->leadLists->listsForUser($user->id);
            $match = $lists->first(
                fn (array $list) => (string) $list['list_id'] === $hash && (string) $list['src'] === $src
            );

            return [
                'list_hash' => $hash,
                'list_src' => $src,
                'list_name' => (string) ($plan['list_name'] ?? $match['list_name'] ?? 'Selected list'),
                'total_leads' => (int) ($match['total_leads'] ?? 0),
                'match_score' => 100,
            ];
        }

        return $this->findBestMatch($user, $plan, $strict);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    public function enrichPlanWithAudience(User $user, array $plan): array
    {
        $explicitHash = trim((string) ($plan['list_hash'] ?? $plan['lead_list_id'] ?? ''));
        $explicitSrc = trim((string) ($plan['list_src'] ?? $plan['lead_list_src'] ?? ''));
        $preferFresh = ! empty($plan['prefer_fresh_audience']);

        // Fresh N requested and no explicit list_hash → LinkedIn fetch+save, never silent engagers reuse.
        if ($preferFresh && $explicitHash === '') {
            $orgId = (int) ($user->current_organization_id ?? 0);
            $built = $orgId > 0
                ? $this->linkedInAudience->tryBuildFromPlan($user, $orgId, $plan)
                : null;

            if ($built !== null) {
                $plan = PlanLeadList::merge(
                    $plan,
                    $built['list_hash'],
                    $built['list_src'],
                    $built['list_name'],
                );
                $plan['audience_status'] = 'attached';
                $plan['audience_leads'] = $built['total_leads'];
                $plan['audience_note'] = $built['list_name'].' ('.$built['total_leads'].' leads, fetched from LinkedIn and saved)';
                $plan['prefer_fresh_audience'] = true;

                return $plan;
            }

            $plan['audience_status'] = 'missing';
            $plan['audience_next_steps'] = $this->nextSteps($plan);

            return $plan;
        }

        $match = $this->resolve($user, $plan, strict: true);

        // Weak fuzzy matches are not good enough when a target size was requested.
        if (
            $match !== null
            && $explicitHash === ''
            && isset($plan['target_count'])
            && (int) ($match['match_score'] ?? 0) < DiscoverProspectsService::STRONG_MATCH_SCORE
        ) {
            $match = null;
        }

        if ($match === null) {
            $orgId = (int) ($user->current_organization_id ?? 0);
            $built = $orgId > 0
                ? $this->linkedInAudience->tryBuildFromPlan($user, $orgId, $plan)
                : null;

            if ($built !== null) {
                $plan = PlanLeadList::merge(
                    $plan,
                    $built['list_hash'],
                    $built['list_src'],
                    $built['list_name'],
                );
                $plan['audience_status'] = 'attached';
                $plan['audience_leads'] = $built['total_leads'];
                $plan['audience_note'] = $built['list_name'].' ('.$built['total_leads'].' leads, auto-sourced from LinkedIn)';

                return $plan;
            }

            $plan['audience_status'] = 'missing';
            $plan['audience_next_steps'] = $this->nextSteps($plan);

            return $plan;
        }

        // Explicit list_hash from tool/user always wins.
        if ($explicitHash !== '' && $explicitSrc !== '') {
            $match = $this->resolve($user, $plan, strict: true) ?? $match;
        }

        $plan = PlanLeadList::merge(
            $plan,
            $match['list_hash'],
            $match['list_src'],
            $match['list_name'],
        );
        $plan['audience_status'] = 'ready';
        $plan['audience_leads'] = $match['total_leads'];
        $plan['audience_note'] = "{$match['list_name']} ({$match['total_leads']} leads)";

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return list<string>
     */
    public function nextSteps(array $plan): array
    {
        $goal = trim((string) ($plan['goal'] ?? $plan['audience'] ?? $plan['icp_notes'] ?? 'your ICP'));

        return [
            'Alex will auto-search LinkedIn for '.$goal.' when your account is connected',
            'Or import / build a lead list in SociFusion → Leads',
            'Or share a competitor LinkedIn company URL to harvest engagers',
            'Launch runs once a list is attached — Autopilot auto-launches when ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array{
     *     list_hash: string,
     *     list_src: string,
     *     list_name: string,
     *     total_leads: int,
     *     match_score: int
     * }|null
     */
    private function findBestMatch(User $user, array $plan, bool $strict): ?array
    {
        $needle = $this->searchNeedle($plan);
        $tokens = array_values(array_filter(
            preg_split('/\s+/', $needle) ?: [],
            fn (string $token) => strlen($token) >= 3,
        ));

        $lists = $this->leadLists->listsForUser($user->id)
            ->filter(fn (array $list) => (int) ($list['total_leads'] ?? 0) > 0)
            ->values();

        if ($lists->isEmpty()) {
            return null;
        }

        $scored = $lists->map(function (array $list) use ($needle, $tokens) {
            $name = Str::lower((string) $list['list_name']);
            $score = 0;

            if ($needle !== '' && str_contains($name, $needle)) {
                $score += 10;
            }

            foreach ($tokens as $token) {
                if (str_contains($name, $token)) {
                    $score += 2;
                }
            }

            if ($score > 0) {
                $score += min(3, (int) floor(((int) $list['total_leads']) / 200));
            }

            return ['list' => $list, 'score' => $score];
        })->sortByDesc('score')->values();

        $best = $scored->first();
        // Size-only / weak token hits must not win in strict mode.
        if (! $best || ($best['score'] ?? 0) < DiscoverProspectsService::STRONG_MATCH_SCORE) {
            if ($strict) {
                return null;
            }

            if (! $best || ($best['score'] ?? 0) <= 0) {
                $largest = $lists->sortByDesc('total_leads')->first();
                if (! $largest) {
                    return null;
                }

                return $this->normalizeListRef(
                    (string) $largest['list_id'],
                    (string) $largest['src'],
                    (string) $largest['list_name'],
                    (int) $largest['total_leads'],
                    0,
                );
            }
        }

        $list = $best['list'];

        return $this->normalizeListRef(
            (string) $list['list_id'],
            (string) $list['src'],
            (string) $list['list_name'],
            (int) $list['total_leads'],
            (int) $best['score'],
        );
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function searchNeedle(array $plan): string
    {
        return Str::lower(trim(implode(' ', array_filter([
            $plan['audience'] ?? null,
            $plan['icp_notes'] ?? null,
            $plan['icp']['summary'] ?? null,
            $plan['geography'] ?? null,
            $plan['goal'] ?? null,
        ]))));
    }

    /**
     * @return array{
     *     list_hash: string,
     *     list_src: string,
     *     list_name: string,
     *     total_leads: int,
     *     match_score: int
     * }
     */
    private function normalizeListRef(
        string $listHash,
        string $listSrc,
        string $listName,
        int $totalLeads = 0,
        int $matchScore = 100,
    ): array {
        return [
            'list_hash' => $listHash,
            'list_src' => $listSrc,
            'list_name' => $listName,
            'total_leads' => $totalLeads,
            'match_score' => $matchScore,
        ];
    }
}

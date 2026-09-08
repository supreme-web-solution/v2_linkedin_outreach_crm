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
        $plan = $this->hydrateProfileUrlFromText($plan);
        $oneShot = $this->isOneShotPlan($plan);
        $profileUrl = trim((string) ($plan['profile_url'] ?? $plan['linkedin_url'] ?? ''));

        // One-person profile URL always wins over a stale multi-lead list_hash.
        if ($profileUrl !== '' && preg_match('#linkedin\.com/in/[\w%-]+#i', $profileUrl)) {
            $orgId = (int) ($user->current_organization_id ?? 0);
            $built = $orgId > 0
                ? $this->linkedInAudience->tryBuildFromPlan($user, $orgId, array_merge($plan, [
                    'one_shot' => true,
                    'target_count' => 1,
                    'profile_url' => $profileUrl,
                ]))
                : null;

            if ($built !== null) {
                $plan = PlanLeadList::merge(
                    $plan,
                    $built['list_hash'],
                    $built['list_src'],
                    $built['list_name'],
                );
                $plan['audience_status'] = 'attached';
                $plan['audience_leads'] = 1;
                $plan['audience_note'] = $built['list_name'].' (1 profile from LinkedIn URL)';
                if ($oneShot) {
                    $plan['one_shot'] = true;
                    $plan['one_time'] = true;
                }
                $plan['target_count'] = 1;
                if (! empty($built['profile_detail'])) {
                    $plan['profile_detail'] = $built['profile_detail'];
                }
                if (! empty($built['sample_profiles'])) {
                    $plan['sample_profiles'] = $built['sample_profiles'];
                }
                if (! empty($built['first_degree_only'])) {
                    $plan['first_degree_only'] = true;
                }

                return $plan;
            }
        }

        $explicitHash = trim((string) ($plan['list_hash'] ?? $plan['lead_list_id'] ?? ''));
        $explicitSrc = trim((string) ($plan['list_src'] ?? $plan['lead_list_src'] ?? ''));
        $preferFresh = ! empty($plan['prefer_fresh_audience']);

        // One-shot must not silently reuse a large unrelated list.
        if ($oneShot && $explicitHash !== '' && $explicitSrc !== '') {
            $resolvedExplicit = $this->resolve($user, $plan, strict: true);
            $leads = (int) ($resolvedExplicit['total_leads'] ?? 0);
            if ($leads > 1) {
                unset($plan['list_hash'], $plan['list_src'], $plan['list_name'], $plan['lead_list_id'], $plan['lead_list_src']);
                $explicitHash = '';
                $explicitSrc = '';
                $preferFresh = true;
                $plan['prefer_fresh_audience'] = true;
                $plan['target_count'] = 1;
            }
        }

        // Fresh N requested and no explicit list_hash → LinkedIn fetch+save, never silent engagers reuse.
        if ($preferFresh && $explicitHash === '') {
            if (! $this->shouldAutoSearchLinkedIn($plan)) {
                $plan['audience_status'] = 'missing';
                $plan['audience_next_steps'] = $this->nextSteps($plan);

                return $plan;
            }

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
                if (! empty($built['sample_profiles'])) {
                    $plan['sample_profiles'] = $built['sample_profiles'];
                }
                if (! empty($built['profile_detail'])) {
                    $plan['profile_detail'] = $built['profile_detail'];
                }

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
            if (! $this->shouldAutoSearchLinkedIn($plan)) {
                $plan['audience_status'] = 'missing';
                $plan['audience_next_steps'] = $this->nextSteps($plan);

                return $plan;
            }

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
    private function isOneShotPlan(array $plan): bool
    {
        return app(PlanSequenceNodeBuilder::class)->isOneShotIntent($plan);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function hydrateProfileUrlFromText(array $plan): array
    {
        $existing = trim((string) ($plan['profile_url'] ?? $plan['linkedin_url'] ?? ''));
        if ($existing !== '' && preg_match('#linkedin\.com/in/[\w%-]+#i', $existing)) {
            $plan['profile_url'] = $existing;

            return $plan;
        }

        $blob = implode(' ', array_filter([
            (string) ($plan['goal'] ?? ''),
            (string) ($plan['audience'] ?? ''),
            (string) ($plan['icp_notes'] ?? ''),
            (string) ($plan['message'] ?? ''),
            (string) ($plan['icp_summary'] ?? ''),
        ]));

        if (preg_match('#https?://(?:www\.)?linkedin\.com/in/[\w%-]+/?#i', $blob, $m)) {
            $plan['profile_url'] = $m[0];
            $plan['linkedin_url'] = $m[0];
        }

        return $plan;
    }

    /**
     * Email/WhatsApp-only one-shots must not trigger LinkedIn people search
     * (that produced garbage lists from webinar copy / email addresses).
     *
     * @param  array<string, mixed>  $plan
     */
    private function shouldAutoSearchLinkedIn(array $plan): bool
    {
        if (! empty($plan['linkedin_url']) || ! empty($plan['profile_url'])) {
            return true;
        }

        $channels = Str::lower((string) ($plan['preferred_channels'] ?? $plan['channels'] ?? ''));
        $mentionsLinkedIn = $channels === '' || str_contains($channels, 'linkedin');
        $emailOnly = str_contains($channels, 'email') && ! $mentionsLinkedIn;
        $whatsappOnly = str_contains($channels, 'whatsapp') && ! $mentionsLinkedIn && ! str_contains($channels, 'email');
        $instagramOnly = str_contains($channels, 'instagram') && ! $mentionsLinkedIn;
        $telegramOnly = str_contains($channels, 'telegram') && ! $mentionsLinkedIn;
        $twitterOnly = (str_contains($channels, 'twitter') || preg_match('/\bx\b/', $channels)) && ! $mentionsLinkedIn;

        if ($emailOnly || $whatsappOnly || $instagramOnly || $telegramOnly || $twitterOnly) {
            return false;
        }

        $blob = (string) ($plan['audience'] ?? $plan['icp_notes'] ?? $plan['goal'] ?? '');
        if (preg_match('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $blob) && ! $mentionsLinkedIn) {
            return false;
        }

        return true;
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

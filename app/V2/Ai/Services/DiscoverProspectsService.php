<?php

namespace App\V2\Ai\Services;

use App\Models\Audience;
use App\Models\AudienceList;
use App\Models\User;
use App\V2\Ai\Support\PlanLeadList;
use App\V2\Integrations\Mindcase\MindcaseClient;
use App\V2\Outreach\OutreachChannelGuard;
use App\V2\Services\LeadListService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DiscoverProspectsService
{
    /** Minimum score to treat a saved list as an intentional match (not engagers fallback). */
    public const STRONG_MATCH_SCORE = 10;

    /** Max prospects fetched/saved in one pull across any channel. */
    public const MAX_TARGET_COUNT = 100;

    /** Prevents discover() → discoverParallel() → discover() stack overflow. */
    private bool $insideParallel = false;

    public function __construct(
        private readonly LeadListService $leadLists,
        private readonly ProspectAudienceResolverService $audienceResolver,
        private readonly LinkedInAudienceBuilderService $linkedInAudience,
        private readonly InstagramAudienceBuilderService $instagramAudience,
        private readonly WorkspaceContextService $workspaceContext,
        private readonly SingleChannelOutreachService $singleChannel,
        private readonly OutreachChannelGuard $channelGuard,
        private readonly MindcaseClient $mindcase,
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
        ?string $profileUrl = null,
        string $platform = 'linkedin',
    ): array {
        $platform = Str::lower(trim($platform));
        if ($platform === '') {
            $platform = 'auto';
        }

        if (app(UserTurnIntentService::class)->wantsFreshProspectPull($query)) {
            $preferFresh = true;
        }

        if (! $this->insideParallel && $this->shouldUseParallelDiscovery($user, $platform)) {
            $this->insideParallel = true;
            try {
                return $this->discoverParallel(
                $user,
                $query,
                $competitors,
                $limit,
                $targetCount,
                $preferFresh,
                $geography,
                $networkDegree,
                $title,
                $company,
                $openLink,
                $profileUrl,
            );
            } finally {
                $this->insideParallel = false;
            }
        }

        if (in_array($platform, ['instagram', 'ig'], true)) {
            return $this->discoverInstagram(
                $user,
                $query,
                $this->normalizeTargetCount($targetCount) ?? max(1, $limit),
                $profileUrl,
                $geography,
                $preferFresh,
            );
        }

        $limit = max(1, min(20, $limit));
        $targetCount = $this->normalizeTargetCount($targetCount);
        $query = trim($this->workspaceContext->enrichDiscoveryQuery(
            $user,
            (int) ($user->current_organization_id ?? 0),
            $query,
        ));
        $competitorNames = array_values(array_filter(array_map('trim', explode(',', (string) $competitors))));
        if ($targetCount === null) {
            $targetCount = $this->inferCountFromQuery($query);
        }

        // Any explicit net-new size (or prefer_fresh) → LinkedIn search + SAVE, do not reuse engagers lists.
        $forceFresh = $preferFresh || $targetCount !== null || ($profileUrl !== null && trim($profileUrl) !== '');

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
            'target_count' => $profileUrl ? 1 : ($targetCount ?? $this->inferCountFromQuery($query) ?? $limit),
            'prefer_fresh_audience' => $forceFresh,
            'geography' => $geography,
            'network_degree' => $networkDegree,
            'title' => $title,
            'current_company' => $company,
            'open_link' => $openLink,
            'profile_url' => $profileUrl,
            'linkedin_url' => $profileUrl,
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
            if (! empty($autoSourced['sample_profiles'])) {
                $planProbe['sample_profiles'] = $autoSourced['sample_profiles'];
            }
                $resolved = $autoSourced;
                $merged = $merged->prepend(array_merge($autoSourced, [
                    'origin' => 'linkedin_search',
                    'primary_channel' => 'linkedin',
                    'platform' => 'linkedin',
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
                'Connect LinkedIn in SociFusion → Integrations, then ask again — Soci will fetch and save matching profiles.',
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
                'Soci retries broader variants automatically — ask again, or check LinkedIn under Integrations.',
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
            $samples = $autoSourced['sample_profiles'] ?? [];
            if (is_array($samples) && $samples !== []) {
                $nextSteps[] = 'Matching profiles (share these links + details with the user to confirm):';
                foreach (array_slice($samples, 0, 5) as $i => $profile) {
                    if (! is_array($profile)) {
                        continue;
                    }
                    $line = ($i + 1).'. '
                        .($profile['name'] ?? 'Unknown')
                        .(isset($profile['headline']) && $profile['headline'] ? ' — '.$profile['headline'] : '')
                        .(isset($profile['company']) && $profile['company'] ? ' @ '.$profile['company'] : '')
                        .(isset($profile['location']) && $profile['location'] ? ' ('.$profile['location'].')' : '')
                        .(isset($profile['profile_url']) && $profile['profile_url'] ? ' | '.$profile['profile_url'] : '');
                    $nextSteps[] = $line;
                    if (! empty($profile['about'])) {
                        $nextSteps[] = '   About: '.$profile['about'];
                    }
                }
            }
            if (! empty($autoSourced['profile_detail']) && is_array($autoSourced['profile_detail'])) {
                $detail = $autoSourced['profile_detail'];
                $nextSteps[] = 'Profile detail loaded for '.($detail['name'] ?? 'this person')
                    .(! empty($detail['headline']) ? ' — '.$detail['headline'] : '')
                    .'. Use this when drafting a personalized one-shot.';
            }
            if ($targetCount !== null && $found < $targetCount) {
                $nextSteps[] = "Found {$found} of ~{$targetCount} requested. Ask Soci to discover again to grow this same saved list (<=100 per run).";
            }
            $nextSteps = array_merge($nextSteps, [
                '- propose_strategy or draft_campaign_plan USING this list_hash (do not swap to an old engagers list)',
                '- prepare_enrichment if emails/phones are missing',
                '- Launch when ready',
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
            'platform' => 'linkedin',
            'primary_channel' => 'linkedin',
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
            'sample_profiles' => $autoSourced['sample_profiles'] ?? [],
            'profile_detail' => $autoSourced['profile_detail'] ?? null,
            'campaign_hint' => $firstDegree
                ? '1st-degree list: plan LinkedIn DM sequence only (no send_invite / invite_accepted).'
                : null,
            'next_steps' => $nextSteps,
            'limits' => [
                'note' => 'When target_count / prefer_fresh is set, Soci ALWAYS fetches LinkedIn profiles and saves a new list. Saved engagers lists are only reused on a strong name match when you did not ask for a fresh count.',
                'linkedin_search_cap' => 'Each LinkedIn fetch returns up to ~100 profiles and is saved under Leads. Repeat discover_prospects to grow the same list.',
                'search_params' => 'Pass geography, network_degree (1st|2nd|3rd / F|S|O), title, company, open_link. Unipile classic people search.',
                'competitor_harvest' => 'Optional: prepare_competitor_harvest for engagers from a competitor post/profile.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function discoverInstagram(
        User $user,
        string $query,
        int $limit,
        ?string $profileUrl,
        ?string $geography = null,
        bool $forceFresh = false,
    ): array {
        $query = $this->instagramQueryWithLocation($query, $geography);

        // Keyword is primary. Only force username lookup for @handle or profile URL.
        $usernames = [];
        if ($profileUrl && preg_match('~instagram\.com/([^/?#]+)~i', $profileUrl, $m)) {
            $usernames[] = $m[1];
        } elseif (preg_match('/^@[\w.]{2,30}$/', trim($query))) {
            $usernames[] = ltrim(trim($query), '@');
        } elseif (preg_match('~instagram\.com/([^/?#]+)~i', trim($query), $m)) {
            $usernames[] = $m[1];
        }

        $built = $this->instagramAudience->searchAndPersist(
            $user,
            $query,
            max(1, min(self::MAX_TARGET_COUNT, $limit)),
            $usernames !== [] ? $usernames : null,
            forceFresh: $forceFresh,
        );

        if ($built === null) {
            $failureReason = $this->instagramAudience->lastError()
                ?? 'Instagram search returned no profiles. Check MINDCASE_API_KEY or try a simpler keyword.';

            return [
                'query' => $query,
                'platform' => 'instagram',
                'lists' => [],
                'best_match' => null,
                'ready_for_campaign' => false,
                'auto_sourced' => false,
                'search_failed' => true,
                'failure_reason' => $failureReason,
                'sample_profiles' => [],
                'next_steps' => [
                    $failureReason,
                    'Try again with a shorter keyword, or search from Leads → Find Instagram leads (max '.self::MAX_TARGET_COUNT.' per pull).',
                ],
            ];
        }

        $samples = $built['sample_profiles'] ?? [];
        $next = [
            'Fetched Instagram audience: '.$built['list_name'].' ('.$built['total_leads'].').',
            'list_hash='.$built['list_hash'].' list_src=csv',
            'draft_campaign_plan with channels=Instagram + this list_hash (one_shot for a greeting).',
        ];
        if (is_array($samples) && $samples !== []) {
            $next[] = 'Sample profiles:';
            foreach (array_slice($samples, 0, 5) as $i => $p) {
                if (! is_array($p)) {
                    continue;
                }
                $next[] = ($i + 1).'. @'.($p['username'] ?? '').' — '.($p['name'] ?? '')
                    .(isset($p['profile_url']) ? ' | '.$p['profile_url'] : '');
            }
        }

        return [
            'query' => $query,
            'platform' => 'instagram',
            'primary_channel' => 'instagram',
            'lists' => [array_merge($built, ['primary_channel' => 'instagram'])],
            'best_match' => array_merge($built, ['primary_channel' => 'instagram']),
            'total_leads_in_matches' => $built['total_leads'],
            'ready_for_campaign' => true,
            'auto_sourced' => true,
            'fresh_fetch' => true,
            'sample_profiles' => $samples,
            'campaign_hint' => 'Instagram list: single-channel Instagram outreach only — do not mix LinkedIn/email in the same sequence.',
            'next_steps' => $next,
        ];
    }

    /**
     * Fan-out discovery across connected channels (LinkedIn + Instagram). Each list is tagged primary_channel.
     *
     * @return array<string, mixed>
     */
    private function discoverParallel(
        User $user,
        string $query,
        ?string $competitors,
        int $limit,
        ?int $targetCount,
        bool $preferFresh,
        ?string $geography,
        ?string $networkDegree,
        ?string $title,
        ?string $company,
        ?bool $openLink,
        ?string $profileUrl,
    ): array {
        if (app(UserTurnIntentService::class)->wantsFreshProspectPull($query)) {
            $preferFresh = true;
        }

        $channels = $this->parallelDiscoveryChannels($user);
        if ($targetCount === null) {
            $targetCount = $this->inferCountFromQuery($query);
        }
        if ($channels === []) {
            return [
                'mode' => 'parallel',
                'query' => $query,
                'platforms_searched' => [],
                'lists' => [],
                'ready_for_campaign' => false,
                'next_steps' => [
                    'Connect LinkedIn and/or Instagram on Integrations, then ask Soci to find customers again.',
                ],
            ];
        }

        $allocationPlan = app(PlatformAllocationService::class)->plan($user, $targetCount, $channels);
        $allocation = $allocationPlan['allocation'];
        $forceFreshPull = $preferFresh || $targetCount !== null;
        $activeChannels = array_values(array_filter(
            $allocation,
            fn (int $count) => $count > 0,
        ));
        $activeChannelCount = count($activeChannels);
        $streamProgress = $activeChannelCount > 1;
        $progress = app(WebChatTurnProgressService::class);
        $completedChannels = 0;

        if ($streamProgress) {
            $progress->status('Searching LinkedIn profiles…');
        } elseif (app(WebChatTurnProgressService::class)->active()) {
            app(WebChatTurnProgressService::class)->status('Finding prospects…');
        }

        $channelResults = [];
        foreach ($allocation as $channel => $channelTarget) {
            if ($channelTarget <= 0) {
                continue;
            }
            if ($channel === 'linkedin') {
                $channelResults['linkedin'] = $this->discover(
                    $user,
                    $query,
                    $competitors,
                    $limit,
                    $channelTarget,
                    $forceFreshPull,
                    $geography,
                    $networkDegree,
                    $title,
                    $company,
                    $openLink,
                    $profileUrl,
                    'linkedin',
                );
            } elseif ($channel === 'instagram') {
                Log::info('[Soci] Instagram discovery starting', [
                    'user_id' => $user->id,
                    'target' => $channelTarget,
                    'force_fresh' => $forceFreshPull,
                ]);
                $channelResults['instagram'] = $this->discoverInstagram(
                    $user,
                    $query,
                    $channelTarget,
                    $profileUrl,
                    $geography,
                    $forceFreshPull,
                );
            } else {
                continue;
            }

            $completedChannels++;
            if ($streamProgress) {
                $remaining = $activeChannelCount - $completedChannels;
                $progress->afterDiscoveryChannel(
                    channel: (string) $channel,
                    result: $channelResults[$channel],
                    moreChannelsPending: $remaining > 0,
                    nextLabel: $remaining > 0 ? $this->nextDiscoveryProgressLabel($allocation, $completedChannels) : null,
                    allChannelResults: $channelResults,
                );
            }
        }

        $lists = collect($channelResults)
            ->flatMap(function (array $result, string $channel) {
                return collect($result['lists'] ?? [])
                    ->map(fn (array $row) => array_merge($row, [
                        'primary_channel' => $row['primary_channel'] ?? $channel,
                        'first_degree_only' => (bool) ($row['first_degree_only'] ?? $result['first_degree_only'] ?? false),
                    ]));
            })
            ->values()
            ->all();

        $ready = collect($channelResults)->contains(fn (array $r) => ! empty($r['ready_for_campaign']));
        $next = [
            $allocationPlan['summary'],
            'Do not call draft_campaign_plan yourself if staged_campaigns is already filled — one campaign is created per platform.',
            'First message is written per person after research. LinkedIn DMs wait until the invite is accepted.',
        ];

        foreach ($channelResults as $channel => $result) {
            $best = $result['best_match'] ?? null;
            if (is_array($best) && ! empty($best['list_hash'])) {
                $next[] = strtoupper($channel).': '.$best['list_name']
                    .' ('.($best['total_leads'] ?? 0).' leads) list_hash='.$best['list_hash']
                    .' list_src='.($best['list_src'] ?? 'csv');
            }
        }

        return [
            'mode' => 'parallel',
            'query' => $query,
            'allocation' => $allocationPlan,
            'platforms_searched' => array_keys($channelResults),
            'channel_results' => $channelResults,
            'lists' => $lists,
            'total_leads_in_matches' => (int) collect($lists)->sum(fn (array $r) => (int) ($r['total_leads'] ?? 0)),
            'ready_for_campaign' => $ready,
            'single_channel_rule' => 'Never mix channels in one outreach sequence. Follow up on the channel where the prospect was found.',
            'next_steps' => $next,
        ];
    }

    public function normalizeTargetCount(?int $targetCount): ?int
    {
        if ($targetCount === null || $targetCount <= 0) {
            return null;
        }

        return max(1, min(self::MAX_TARGET_COUNT, $targetCount));
    }

    public function inferCountFromQuery(string $query): ?int
    {
        if (preg_match('/\b(?:more|another|extra)\s+(\d{1,3})\b/i', $query, $matches)) {
            return $this->normalizeTargetCount((int) $matches[1]);
        }

        if (preg_match('/\b(\d{1,3})\s+more\b/i', $query, $matches)) {
            return $this->normalizeTargetCount((int) $matches[1]);
        }

        if (preg_match('/\b(\d{1,3})\s*(customers|prospects|leads|people|profiles)\b/i', $query, $matches)) {
            return $this->normalizeTargetCount((int) $matches[1]);
        }

        if (preg_match('/\b(\d{1,3})\s+of\s+them\b/i', $query, $matches)) {
            return $this->normalizeTargetCount((int) $matches[1]);
        }

        if (preg_match('/\blike\s+(\d{1,3})\b/i', $query, $matches)) {
            return $this->normalizeTargetCount((int) $matches[1]);
        }

        if (preg_match('/\b(\d{1,3})\s+or\s+above\b/i', $query, $matches)) {
            return $this->normalizeTargetCount((int) $matches[1]);
        }

        if (preg_match('/\b(?:about|around|up\s+to)\s+(\d{1,3})\b/i', $query, $matches)) {
            return $this->normalizeTargetCount((int) $matches[1]);
        }

        if (preg_match('/\bjust\s+(\d{1,3})\b/i', $query, $matches)) {
            return $this->normalizeTargetCount((int) $matches[1]);
        }

        return null;
    }

    private function shouldUseParallelDiscovery(User $user, string $platform): bool
    {
        $platform = Str::lower(trim($platform));

        if (in_array($platform, ['all', 'parallel', 'multi', 'multichannel'], true)) {
            return true;
        }

        // Explicit single-channel requests must never fan out to Instagram/LinkedIn together.
        if (in_array($platform, ['linkedin', 'li', 'instagram', 'ig'], true)) {
            return false;
        }

        if (in_array($platform, ['auto', 'default', ''], true)) {
            // Auto parallel only when both are connected AND caller did not force single-channel.
            return count($this->parallelDiscoveryChannels($user)) >= 2;
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function parallelDiscoveryChannels(User $user): array
    {
        $channels = [];

        if ($this->channelGuard->isChannelConnected($user->id, 'linkedin')) {
            $channels[] = 'linkedin';
        }

        if ($this->channelGuard->isChannelConnected($user->id, 'instagram') && $this->mindcase->configured()) {
            $channels[] = 'instagram';
        }

        return $channels;
    }

    /**
     * @param  array<string, int>  $allocation
     */
    private function nextDiscoveryProgressLabel(array $allocation, int $completedChannels): string
    {
        $pending = [];
        $index = 0;
        foreach ($allocation as $channel => $target) {
            if ($target <= 0) {
                continue;
            }
            $index++;
            if ($index <= $completedChannels) {
                continue;
            }
            $pending[] = ucfirst((string) $channel);
        }

        if ($pending === []) {
            return 'Finishing up…';
        }

        $next = $pending[0];

        return "Searching {$next} profiles now — this can take a few minutes.";
    }

    private function instagramQueryWithLocation(string $query, ?string $geography): string
    {
        $base = trim($query);
        $geo = trim((string) $geography);
        if ($geo === '') {
            return $base;
        }

        $geoLower = Str::lower($geo);
        $baseLower = Str::lower($base);
        if ($baseLower !== '' && str_contains($baseLower, $geoLower)) {
            return $base;
        }

        return trim($base.' '.$geo);
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

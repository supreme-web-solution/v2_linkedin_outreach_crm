<?php

namespace App\V2\Ai\Services;

use App\Models\AiEmployeeSetting;
use App\Models\User;
use App\V2\Ai\Support\ResearchUrlValidator;
use App\V2\Services\CallCalendarService;
use App\V2\Services\CallOrchestrationService;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Resolved business profile, ICP, and conversion assets for Soci.
 */
class WorkspaceContextService
{
    public function __construct(
        private readonly AiEmployeeSettingsService $settingsService,
        private readonly CallCalendarService $calendar,
        private readonly CallOrchestrationService $calls,
    ) {}

    public function businessProfileComplete(AiEmployeeSetting $settings): bool
    {
        $profile = $this->businessProfile($settings);

        return trim((string) ($profile['summary'] ?? '')) !== ''
            || trim((string) ($profile['raw_text'] ?? '')) !== '';
    }

    public function conversionAssetsComplete(AiEmployeeSetting $settings): bool
    {
        $assets = $this->conversionAssets($settings);

        return trim((string) ($assets['sales_page_url'] ?? '')) !== ''
            || trim((string) ($assets['webinar_url'] ?? '')) !== '';
    }

    public function hasLaunchConversionAsset(User $user, AiEmployeeSetting $settings): bool
    {
        return $this->conversionAssetsComplete($settings)
            || $this->resolveMeetingLink($user, $settings) !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function businessProfile(AiEmployeeSetting $settings): array
    {
        $meta = is_array($settings->meta) ? $settings->meta : [];
        $profile = is_array($meta['business_profile'] ?? null) ? $meta['business_profile'] : [];

        if (array_key_exists('website_url', $profile)) {
            $profile['website_url'] = ResearchUrlValidator::sanitize($profile['website_url'] ?? null);
        }

        return $profile;
    }

    /**
     * @return array<string, mixed>
     */
    public function storedIcp(AiEmployeeSetting $settings): array
    {
        $meta = is_array($settings->meta) ? $settings->meta : [];
        $stored = is_array($meta['stored_icp'] ?? null) ? $meta['stored_icp'] : [];
        $icp = is_array($stored['icp'] ?? null) ? $stored['icp'] : [];

        return $icp;
    }

    /**
     * @return array<string, mixed>
     */
    public function conversionAssets(AiEmployeeSetting $settings): array
    {
        $meta = is_array($settings->meta) ? $settings->meta : [];
        $assets = is_array($meta['conversion_assets'] ?? null) ? $meta['conversion_assets'] : [];

        return $assets;
    }

    public function resolveMeetingLink(User $user, AiEmployeeSetting $settings): ?string
    {
        $assets = $this->conversionAssets($settings);
        $stored = trim((string) ($assets['meeting_link'] ?? ''));
        if ($stored !== '') {
            return $stored;
        }

        $callSettings = $this->calls->settingsFor($user);
        $manual = trim((string) ($callSettings['calendar_url'] ?? ''));
        if ($manual !== '' && ($callSettings['use_app_booking_link'] ?? true) === false) {
            return $manual;
        }

        if ($this->calendar->isAvailable($user->id)) {
            return 'app_booking';
        }

        return null;
    }

    /**
     * Audience search string from this turn's interpreter + onboarding ICP.
     * Uses the user's words — no canned titles or industries.
     *
     * @param  array<string, mixed>  $plan
     * @return array{query:string,title:?string,geography:?string,audience_name:string}
     */
    public function discoverySearchHints(User $user, int $organizationId, array $plan = []): array
    {
        $settings = $this->settingsService->for($user, $organizationId);
        $icp = $this->storedIcp($settings);
        $profile = $this->businessProfile($settings);
        $semantic = is_array($plan['semantic'] ?? null) ? $plan['semantic'] : [];
        $objective = is_array($plan['objective'] ?? null) ? $plan['objective'] : [];
        $constraints = is_array($plan['constraints'] ?? null) ? $plan['constraints'] : [];

        $handoff = trim((string) ($semantic['handoff_query'] ?? $plan['handoff_query'] ?? ''));
        $segment = trim((string) ($objective['segment'] ?? $semantic['target_segment'] ?? ''));
        $searchQuery = trim((string) Arr::get($icp, 'search_query', ''));
        $searchTitles = is_array($icp['search_titles'] ?? null) ? $icp['search_titles'] : [];
        $decisionMaker = trim((string) Arr::get($icp, 'decision_maker', ''));
        $industry = trim((string) Arr::get($icp, 'industry', ''));
        $summary = trim((string) Arr::get($icp, 'summary', Arr::get($icp, 'who_we_sell_to', '')));
        $geography = trim((string) (
            $semantic['geography']
            ?? $constraints['geography']
            ?? Arr::get($icp, 'geography')
            ?? ($profile['geography'] ?? '')
        ));

        $audience = $handoff !== ''
            ? $handoff
            : ($segment !== '' ? $segment : ($searchQuery !== '' ? $searchQuery : trim(implode(' ', array_filter([$decisionMaker, $industry, $summary])))));

        $titleFromIcp = trim((string) ($searchTitles[0] ?? ''));
        $titleSource = $segment !== '' ? $segment : ($titleFromIcp !== '' ? $titleFromIcp : $decisionMaker);

        return [
            'query' => Str::limit($audience, 200, ''),
            'title' => $this->firstAudiencePhrase($titleSource),
            'geography' => $geography !== '' ? $geography : null,
            'audience_name' => Str::limit(
                $segment !== '' ? $segment : ($decisionMaker !== '' ? $decisionMaker : ($searchQuery !== '' ? $searchQuery : 'LinkedIn Search')),
                80,
                '',
            ),
        ];
    }

    /**
     * First clause of whatever audience the user/LLM wrote — not a product title list.
     */
    private function firstAudiencePhrase(string $text): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $parts = preg_split('/\s*(?:,|\/| and )\s*/i', $text, 2) ?: [];
        $chunk = trim((string) ($parts[0] ?? ''));
        $chunk = trim($chunk, " \t\n\r.-");
        if ($chunk === '' || strlen($chunk) > 60) {
            return null;
        }

        return $chunk;
    }

    /**
     * Prefer interpreter/onboarding audience words over a long strategy essay.
     */
    public function enrichDiscoveryQuery(User $user, int $organizationId, string $query, array $plan = []): string
    {
        $query = trim($query);
        if (app(UserTurnIntentService::class)->isProceedWithTargetCount($query)) {
            $query = '';
        }

        $hints = $this->discoverySearchHints($user, $organizationId, $plan);
        if ($hints['query'] !== '') {
            return $hints['query'];
        }

        return $query;
    }

    /**
     * @return array{offer:string, website:?string, geography:string, notes:?string}
     */
    public function icpInputsFromProfile(AiEmployeeSetting $settings): array
    {
        $profile = $this->businessProfile($settings);
        $raw = trim((string) ($profile['raw_text'] ?? ''));
        $summary = trim((string) ($profile['summary'] ?? ''));
        $website = ResearchUrlValidator::sanitize($profile['website_url'] ?? null);

        $offer = $summary !== '' ? $summary : Str::limit($raw, 500);
        $notes = $raw !== '' && $summary !== '' && $raw !== $summary
            ? Str::limit($raw, 4000)
            : null;

        return [
            'offer' => $offer !== '' ? $offer : 'B2B services',
            'website' => $website,
            'geography' => trim((string) ($profile['geography'] ?? '')) ?: 'Global',
            'notes' => $notes,
        ];
    }

    public function agentContextBlock(User $user, int $organizationId): string
    {
        $settings = $this->settingsService->for($user, $organizationId);
        $profile = $this->businessProfile($settings);
        $icp = $this->storedIcp($settings);
        $assets = $this->conversionAssets($settings);
        $meeting = $this->resolveMeetingLink($user, $settings);

        $lines = [];

        if ($summary = trim((string) ($profile['summary'] ?? ''))) {
            $lines[] = 'Owner business: '.$summary;
        }

        if ($icp !== []) {
            if ($who = trim((string) Arr::get($icp, 'who_we_sell_to', Arr::get($icp, 'summary', '')))) {
                $lines[] = 'ICP (who we sell to): '.$who;
            }
            $icpLine = collect([
                Arr::get($icp, 'industry'),
                Arr::get($icp, 'decision_maker'),
                Arr::get($icp, 'likely_pain'),
            ])->filter()->implode(' · ');
            if ($icpLine !== '') {
                $lines[] = 'ICP buyers / pain: '.$icpLine;
            }
            if ($search = trim((string) Arr::get($icp, 'search_query', ''))) {
                $lines[] = 'ICP search: '.$search;
            }
            if ($angle = trim((string) Arr::get($icp, 'outreach_angle', ''))) {
                $lines[] = 'ICP first-message angle: '.$angle;
            }
            if ($outcome = trim((string) Arr::get($icp, 'primary_outcome', ''))) {
                $lines[] = 'ICP win: '.$outcome;
            }
        }

        $copyPrefs = app(OutboundCopyPrefsService::class)->for($settings);
        if ($tone = trim((string) ($copyPrefs['tone'] ?? ''))) {
            $lines[] = 'Owner tone preference: '.$tone;
        }
        if ($angle = trim((string) ($copyPrefs['preferred_angle'] ?? ''))) {
            $lines[] = 'Preferred offer angle: '.$angle;
        }
        if ($style = trim((string) ($copyPrefs['style_notes'] ?? ''))) {
            $lines[] = 'Style notes: '.Str::limit($style, 400);
        }
        if (($copyPrefs['do_not_say'] ?? []) !== []) {
            $lines[] = 'Do not say: '.implode('; ', array_slice($copyPrefs['do_not_say'], 0, 8));
        }
        foreach (array_slice($copyPrefs['proof_points'] ?? [], 0, 3) as $i => $proof) {
            if (! is_array($proof)) {
                continue;
            }
            $bits = array_filter([
                $proof['title'] ?? null,
                $proof['outcome'] ?? null,
                $proof['industry'] ?? null,
                $proof['integration'] ?? null,
                $proof['summary'] ?? null,
            ]);
            if ($bits !== []) {
                $lines[] = 'Proof '.($i + 1).': '.Str::limit(implode(' — ', $bits), 220);
            }
        }

        if ($sales = trim((string) ($assets['sales_page_url'] ?? ''))) {
            $lines[] = 'Sales page (send when prospect wants to read/discover): '.$sales;
        }

        if ($webinar = trim((string) ($assets['webinar_url'] ?? ''))) {
            $lines[] = 'Webinar (send when prospect wants to watch/learn): '.$webinar;
        }

        if ($meeting === 'app_booking') {
            $lines[] = 'Meeting: use book_meeting when they ask to talk, or when the page/webinar did not convert (last card). App booking is available.';
        } elseif (is_string($meeting) && $meeting !== '') {
            $lines[] = 'Meeting (last card — book_meeting or include this URL): '.$meeting;
        }

        if ($lines === []) {
            return '';
        }

        return "\n\nWorkspace context:\n".implode("\n", $lines);
    }

    /**
     * Machine-readable onboarding profile for planner constraints.
     *
     * @return array<string,mixed>
     */
    public function workspaceGoalProfile(AiEmployeeSetting $settings): array
    {
        $meta = is_array($settings->meta) ? $settings->meta : [];
        $profile = is_array($meta['workspace_goal_profile'] ?? null) ? $meta['workspace_goal_profile'] : [];

        return array_merge([
            'goal' => null,
            'preferred_channels' => [],
            'must_connect' => [],
            'send_policy' => 'approval_required',
            'new_vs_existing_preference' => 'reuse_first',
        ], $profile);
    }

    public function inboxConversionGuide(User $user, int $organizationId): string
    {
        $settings = $this->settingsService->for($user, $organizationId);
        $assets = $this->conversionAssets($settings);
        $sales = trim((string) ($assets['sales_page_url'] ?? ''));
        $webinar = trim((string) ($assets['webinar_url'] ?? ''));
        $meeting = $this->resolveMeetingLink($user, $settings);

        $lines = [
            'Conversion ladder (earned pitch — Gong/Braun style):',
            '1. First reply / qualifying answer (referrals, outbound, inbound) → ONE question. No product dump, no links, no calendar.',
            '2. Permission only after they want a more predictable pipeline, ask for details, or ask how it works.',
        ];

        if ($sales !== '' && $webinar !== '') {
            $lines[] = '3. Wants to read / tell-me-more / pricing → sales page: '.$sales;
            $lines[] = '4. Wants to watch / webinar / demo → webinar: '.$webinar;
        } elseif ($sales !== '') {
            $lines[] = '3. Only sales page is configured — use it for both “tell me more” and “show me how”: '.$sales;
        } elseif ($webinar !== '') {
            $lines[] = '3. Only webinar is configured — use it for both “tell me more” and “show me how”: '.$webinar;
        } else {
            $lines[] = '3. No sales page or webinar configured — skip the asset step.';
        }

        if ($meeting !== null) {
            $lines[] = 'Last card — they ask to meet, OR they go quiet / unsure / “I’ll think about it” after the page or webinar → book_meeting and put the link in the message'
                .($meeting === 'app_booking'
                    ? ' (app booking page or stored calendar).'
                    : ': '.$meeting);
        } else {
            $lines[] = 'Last card — no meeting link configured. If they want to talk or cool off after an asset, propose two time windows.';
        }

        $lines[] = 'If only one of sales page or webinar is filled, always use that one. Never send sales page, webinar, and meeting in the same message. Never put any of them in the opener.';

        return implode("\n", $lines);
    }

    /**
     * Owner business + ICP from onboarding — background for inbox replies.
     */
    public function inboxBusinessBrief(User $user, int $organizationId): string
    {
        $settings = $this->settingsService->for($user, $organizationId);
        $profile = $this->businessProfile($settings);
        $icp = $this->storedIcp($settings);

        $lines = ['Your business context (from onboarding — use as background, never paste verbatim):'];

        if ($summary = trim((string) ($profile['summary'] ?? ''))) {
            $lines[] = 'What you sell / do: '.$summary;
        }

        if ($icp !== []) {
            if ($who = trim((string) Arr::get($icp, 'who_we_sell_to', ''))) {
                $lines[] = 'Who we sell to: '.$who;
            }
            $icpParts = array_filter([
                Arr::get($icp, 'industry') ? 'Industry: '.Arr::get($icp, 'industry') : null,
                Arr::get($icp, 'decision_maker') ? 'Buyer: '.Arr::get($icp, 'decision_maker') : null,
                Arr::get($icp, 'likely_pain') ? 'Pain you solve: '.Arr::get($icp, 'likely_pain') : null,
            ]);
            if ($icpParts !== []) {
                $lines[] = implode(' · ', $icpParts);
            }
            if ($angle = trim((string) Arr::get($icp, 'outreach_angle', ''))) {
                $lines[] = 'First-message angle: '.$angle;
            }
        }

        if (count($lines) <= 1) {
            return '';
        }

        return implode("\n", $lines);
    }
}

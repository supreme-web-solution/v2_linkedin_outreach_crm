<?php

namespace App\V2\Ai\Services;

use App\Models\AiEmployeeSetting;
use App\Models\User;
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

    /**
     * @return array<string, mixed>
     */
    public function businessProfile(AiEmployeeSetting $settings): array
    {
        $meta = is_array($settings->meta) ? $settings->meta : [];
        $profile = is_array($meta['business_profile'] ?? null) ? $meta['business_profile'] : [];

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
     * Merge saved ICP into a discovery query when the user prompt is generic.
     */
    public function enrichDiscoveryQuery(User $user, int $organizationId, string $query): string
    {
        $query = trim($query);
        $settings = $this->settingsService->for($user, $organizationId);
        $icp = $this->storedIcp($settings);
        if ($icp === []) {
            return $query;
        }

        $parts = array_filter([
            $query !== '' ? $query : null,
            Arr::get($icp, 'summary'),
            Arr::get($icp, 'decision_maker') ? 'Decision maker: '.Arr::get($icp, 'decision_maker') : null,
            Arr::get($icp, 'industry') ? 'Industry: '.Arr::get($icp, 'industry') : null,
            Arr::get($icp, 'likely_pain') ? 'Pain: '.Arr::get($icp, 'likely_pain') : null,
        ], fn ($v) => is_string($v) && trim($v) !== '');

        return Str::limit(implode('. ', $parts), 900);
    }

    /**
     * @return array{offer:string, website:?string, geography:string, notes:?string}
     */
    public function icpInputsFromProfile(AiEmployeeSetting $settings): array
    {
        $profile = $this->businessProfile($settings);
        $raw = trim((string) ($profile['raw_text'] ?? ''));
        $summary = trim((string) ($profile['summary'] ?? ''));
        $website = trim((string) ($profile['website_url'] ?? '')) ?: null;

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
            $icpLine = collect([
                Arr::get($icp, 'industry'),
                Arr::get($icp, 'decision_maker'),
                Arr::get($icp, 'likely_pain'),
            ])->filter()->implode(' · ');
            if ($icpLine !== '') {
                $lines[] = 'ICP: '.$icpLine;
            }
        }

        if ($sales = trim((string) ($assets['sales_page_url'] ?? ''))) {
            $lines[] = 'Sales page (send when prospect wants to read/discover): '.$sales;
        }

        if ($webinar = trim((string) ($assets['webinar_url'] ?? ''))) {
            $lines[] = 'Webinar (send when prospect wants to watch/learn): '.$webinar;
        }

        if ($meeting === 'app_booking') {
            $lines[] = 'Meeting link: use book_meeting tool when prospect is qualified but page/webinar did not convert (last card).';
        } elseif (is_string($meeting) && $meeting !== '') {
            $lines[] = 'Meeting link (last card): '.$meeting;
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
            'Conversion ladder (only after genuine interest — never in the first reply):',
            '1. Qualify with questions about their situation. No pitch, no links yet.',
        ];

        if ($sales !== '') {
            $lines[] = '2. If they want to read or discover more → share sales page: '.$sales;
        }

        if ($webinar !== '') {
            $lines[] = '3. If they want to watch or see how it works → share webinar: '.$webinar;
        }

        if ($meeting !== null) {
            $lines[] = '4. Last card — if still not convinced after page/webinar → offer a meeting'.($meeting === 'app_booking'
                ? ' (use a booking link when appropriate)'
                : ': '.$meeting);
        }

        $lines[] = 'Never send sales page, webinar, or meeting link in the opening message. Earn the reply first.';

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
            $icpParts = array_filter([
                Arr::get($icp, 'industry') ? 'Industry: '.Arr::get($icp, 'industry') : null,
                Arr::get($icp, 'decision_maker') ? 'Buyer: '.Arr::get($icp, 'decision_maker') : null,
                Arr::get($icp, 'likely_pain') ? 'Pain you solve: '.Arr::get($icp, 'likely_pain') : null,
            ]);
            if ($icpParts !== []) {
                $lines[] = implode(' · ', $icpParts);
            }
        }

        if (count($lines) <= 1) {
            return '';
        }

        return implode("\n", $lines);
    }
}

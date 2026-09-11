<?php

namespace App\V2\Ai\Services;

use App\Models\User;
use App\V2\Services\CallCalendarService;
use App\V2\Services\CallOrchestrationService;
use App\V2\Services\JinaReaderService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class BusinessProfileOnboardingService
{
    public function __construct(
        private readonly AiEmployeeSettingsService $settingsService,
        private readonly JinaReaderService $jina,
        private readonly PlanContentService $planContent,
        private readonly WorkspaceContextService $workspaceContext,
        private readonly CallCalendarService $calendar,
        private readonly CallOrchestrationService $calls,
    ) {}

    /**
     * @return array{message:string, business_profile:array<string,mixed>, icp:array<string,mixed>}
     */
    public function submitBusinessProfile(
        User $user,
        int $organizationId,
        ?string $description = null,
        ?string $websiteUrl = null,
        ?UploadedFile $file = null,
    ): array {
        $description = trim((string) $description);
        $websiteUrl = trim((string) $websiteUrl);

        if ($description === '' && $websiteUrl === '' && $file === null) {
            throw new \InvalidArgumentException('Tell us about your business — paste text, upload a file, or drop a website link.');
        }

        $chunks = [];
        $sources = [];
        $scrapedTitle = null;

        if ($description !== '') {
            $chunks[] = $description;
            $sources[] = 'chat';
        }

        if ($websiteUrl !== '') {
            $scrape = $this->jina->scrape($websiteUrl);
            if ($scrape['ok']) {
                $chunks[] = $scrape['content'];
                $sources[] = 'website';
                $scrapedTitle = $scrape['title'];
                $websiteUrl = $scrape['url'];
            } elseif ($description === '' && $file === null) {
                throw new \RuntimeException($scrape['error'] ?? 'Could not read that website.');
            } else {
                $sources[] = 'website_failed';
            }
        }

        $fileMeta = null;
        if ($file !== null) {
            $extracted = $this->extractUploadedText($file);
            if ($extracted !== '') {
                $chunks[] = $extracted;
                $sources[] = 'file';
            }
            $storedPath = $file->store('business-profiles/'.$organizationId, 'local');
            $fileMeta = [
                'original_name' => $file->getClientOriginalName(),
                'mime' => $file->getMimeType(),
                'path' => $storedPath,
                'extracted_chars' => strlen($extracted),
            ];
        }

        $rawText = trim(implode("\n\n", array_filter($chunks)));
        if ($rawText === '') {
            throw new \RuntimeException('We could not read enough from your input. Try pasting more detail or a different link.');
        }

        $summary = $this->summarizeBusinessText($rawText);
        $icpInputs = [
            'offer' => $summary !== '' ? $summary : Str::limit($rawText, 500),
            'website' => $websiteUrl !== '' ? $websiteUrl : null,
            'geography' => 'Global',
            'notes' => Str::limit($rawText, 4000),
        ];

        $icp = $this->planContent->buildIcp(
            $icpInputs['offer'],
            $icpInputs['website'],
            [],
            $icpInputs['geography'],
            $icpInputs['notes'],
        );

        $settings = $this->settingsService->for($user, $organizationId);
        $meta = is_array($settings->meta) ? $settings->meta : [];

        $meta['business_profile'] = [
            'summary' => $summary !== '' ? $summary : Str::limit($rawText, 400),
            'raw_text' => Str::limit($rawText, 8000),
            'website_url' => $websiteUrl !== '' ? $websiteUrl : null,
            'website_title' => $scrapedTitle,
            'sources' => $sources,
            'file' => $fileMeta,
            'saved_at' => Carbon::now()->toIso8601String(),
        ];

        $meta['stored_icp'] = [
            'icp' => $icp,
            'goal' => is_array($meta['onboarding'] ?? null) ? ($meta['onboarding']['custom_goal'] ?? $meta['onboarding']['goal'] ?? null) : null,
            'saved_at' => Carbon::now()->toIso8601String(),
            'source' => 'onboarding_business_profile',
        ];
        $goalProfile = is_array($meta['workspace_goal_profile'] ?? null) ? $meta['workspace_goal_profile'] : [];
        $goalProfile['business_profile_completed'] = true;
        $goalProfile['updated_at'] = Carbon::now()->toIso8601String();
        $meta['workspace_goal_profile'] = $goalProfile;

        $onboarding = is_array($meta['onboarding'] ?? null) ? $meta['onboarding'] : [];
        $onboarding['business_profile_completed_at'] = Carbon::now()->toIso8601String();
        $onboarding['step'] = 'conversion_assets';
        $meta['onboarding'] = $onboarding;

        $settings->update(['meta' => $meta]);

        return [
            'message' => 'Got it — I learned about your business and built your ideal customer profile.',
            'business_profile' => $meta['business_profile'],
            'icp' => $icp,
        ];
    }

    /**
     * @return array{message:string, conversion_assets:array<string,mixed>}
     */
    public function submitConversionAssets(
        User $user,
        int $organizationId,
        ?string $salesPageUrl = null,
        ?string $webinarUrl = null,
    ): array {
        $salesPageUrl = trim((string) $salesPageUrl);
        $webinarUrl = trim((string) $webinarUrl);

        if ($salesPageUrl === '' && $webinarUrl === '') {
            throw new \InvalidArgumentException('Add at least one — a sales page URL or a webinar URL.');
        }

        foreach (['sales_page' => $salesPageUrl, 'webinar' => $webinarUrl] as $label => $url) {
            if ($url !== '' && ! filter_var($url, FILTER_VALIDATE_URL)) {
                throw new \InvalidArgumentException("Invalid {$label} URL.");
            }
        }

        $settings = $this->settingsService->for($user, $organizationId);
        if (! $this->workspaceContext->businessProfileComplete($settings)) {
            throw new \RuntimeException('Complete your business profile first.');
        }

        $meetingLink = $this->resolveStoredMeetingLink($user);

        $meta = is_array($settings->meta) ? $settings->meta : [];
        $meta['conversion_assets'] = array_filter([
            'sales_page_url' => $salesPageUrl !== '' ? $salesPageUrl : null,
            'webinar_url' => $webinarUrl !== '' ? $webinarUrl : null,
            'meeting_link' => $meetingLink,
            'meeting_link_source' => $meetingLink === null ? null : ($meetingLink === 'app_booking' ? 'app_booking' : 'manual'),
            'saved_at' => Carbon::now()->toIso8601String(),
        ], fn ($v) => $v !== null);

        $onboarding = is_array($meta['onboarding'] ?? null) ? $meta['onboarding'] : [];
        $onboarding['conversion_assets_completed_at'] = Carbon::now()->toIso8601String();
        $onboarding['step'] = 'climax';
        $meta['onboarding'] = $onboarding;
        $goalProfile = is_array($meta['workspace_goal_profile'] ?? null) ? $meta['workspace_goal_profile'] : [];
        $goalProfile['conversion_assets_completed'] = true;
        $goalProfile['updated_at'] = Carbon::now()->toIso8601String();
        $meta['workspace_goal_profile'] = $goalProfile;

        $settings->update(['meta' => $meta]);

        return [
            'message' => $this->conversionAssetsSuccessMessage($salesPageUrl, $webinarUrl, $meetingLink),
            'conversion_assets' => $meta['conversion_assets'],
        ];
    }

    private function conversionAssetsSuccessMessage(string $salesPageUrl, string $webinarUrl, ?string $meetingLink): string
    {
        $parts = [];

        if ($salesPageUrl !== '' && $webinarUrl !== '') {
            $parts[] = 'I\'ll share your **sales page** and **webinar** when prospects show real interest';
        } elseif ($salesPageUrl !== '') {
            $parts[] = 'I\'ll share your **sales page** when prospects show real interest';
        } elseif ($webinarUrl !== '') {
            $parts[] = 'I\'ll share your **webinar** when prospects show real interest';
        }

        $message = 'Perfect — '.implode('. ', $parts).'.';

        if ($meetingLink !== null) {
            $message .= ' Your meeting link stays the last step.';
        }

        return $message;
    }

    private function summarizeBusinessText(string $rawText): string
    {
        $paragraphs = preg_split('/\n{2,}/', $rawText) ?: [];
        $first = trim((string) ($paragraphs[0] ?? $rawText));

        return Str::limit(preg_replace('/\s+/', ' ', $first) ?? $first, 400);
    }

    private function extractUploadedText(UploadedFile $file): string
    {
        $mime = (string) $file->getMimeType();
        $path = $file->getRealPath() ?: '';

        if ($path === '') {
            return '';
        }

        if (str_contains($mime, 'text/') || $file->getClientOriginalExtension() === 'txt') {
            return trim((string) file_get_contents($path));
        }

        if ($mime === 'application/pdf' || $file->getClientOriginalExtension() === 'pdf') {
            return $this->extractPdfText($path);
        }

        return trim(Str::limit((string) file_get_contents($path), 8000));
    }

    private function extractPdfText(string $path): string
    {
        $raw = (string) file_get_contents($path);
        if ($raw === '') {
            return '';
        }

        $parts = [];
        if (preg_match_all('/\(([^\)\\\\]*(?:\\\\.[^\)\\\\]*)*)\)/s', $raw, $matches)) {
            foreach ($matches[1] as $match) {
                $decoded = stripcslashes($match);
                if (strlen(trim($decoded)) >= 2) {
                    $parts[] = $decoded;
                }
            }
        }

        $text = preg_replace('/\s+/', ' ', implode(' ', $parts)) ?? '';

        return trim($text);
    }

    private function resolveStoredMeetingLink(User $user): ?string
    {
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
}

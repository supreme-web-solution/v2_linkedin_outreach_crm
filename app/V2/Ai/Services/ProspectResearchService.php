<?php

namespace App\V2\Ai\Services;

use App\Models\V2OutreachLead;
use App\V2\Services\CompanyWebSearchService;
use App\V2\Services\WebScrapeChainService;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Scrape prospect bio/website links so Soci can write situation-aware first messages.
 */
class ProspectResearchService
{
    public function __construct(
        private readonly WebScrapeChainService $scraper,
        private readonly CompanyWebSearchService $companySearch,
        private readonly ProspectMemoryService $memory,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function research(array $input): array
    {
        $textBlob = implode("\n", array_filter([
            Arr::get($input, 'headline'),
            Arr::get($input, 'about'),
            Arr::get($input, 'bio'),
            Arr::get($input, 'company'),
            Arr::get($input, 'description'),
        ], fn ($v) => is_string($v) && trim($v) !== ''));

        $urls = $this->scraper->extractUrls($textBlob);
        $profileUrl = trim((string) (Arr::get($input, 'profile_url') ?? ''));
        if ($profileUrl !== '' && ! in_array($profileUrl, $urls, true)) {
            array_unshift($urls, $profileUrl);
        }

        $forced = trim((string) (Arr::get($input, 'scrape_url') ?? ''));
        if ($forced !== '') {
            $urls = array_values(array_unique([$forced, ...$urls]));
        }

        $scraped = [];
        foreach (array_slice($urls, 0, 2) as $url) {
            if ($this->scraper->shouldSkipUrl($url)) {
                continue;
            }

            $result = $this->scraper->scrape($url);
            if ($result['ok']) {
                $scraped[] = [
                    'url' => $result['url'],
                    'title' => $result['title'],
                    'excerpt' => Str::limit($result['content'], 2000),
                    'source' => $result['source'],
                ];
                break;
            }
        }

        $signals = $this->inferSignals($textBlob, $scraped);

        return [
            'profile_url' => $profileUrl !== '' ? $profileUrl : null,
            'urls_found' => $urls,
            'scraped' => $scraped,
            'signals' => $signals,
            'researched_at' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function researchLead(V2OutreachLead $lead, ?string $scrapeUrl = null): array
    {
        $meta = is_array($lead->meta) ? $lead->meta : [];
        $existing = is_array($meta['prospect_intelligence'] ?? null) ? $meta['prospect_intelligence'] : [];
        if ($existing !== [] && $scrapeUrl === null && ! empty($existing['scraped'])) {
            return $existing;
        }

        if ($scrapeUrl === null || trim($scrapeUrl) === '') {
            $channel = Str::lower(trim((string) ($meta['primary_channel'] ?? '')));
            if ($channel === 'instagram' && trim((string) ($meta['instagram_handle'] ?? '')) !== '') {
                $scrapeUrl = 'https://www.instagram.com/'.ltrim(trim((string) $meta['instagram_handle']), '@').'/';
            } elseif ($channel === 'twitter' && trim((string) ($meta['twitter_handle'] ?? '')) !== '') {
                $scrapeUrl = 'https://x.com/'.ltrim(trim((string) $meta['twitter_handle']), '@');
            } elseif ($channel === 'telegram' && trim((string) ($meta['telegram_handle'] ?? '')) !== '') {
                $scrapeUrl = 'https://t.me/'.ltrim(trim((string) $meta['telegram_handle']), '@');
            }
        }

        $intel = $this->research([
            'headline' => $lead->headline,
            'about' => Arr::get($meta, 'about') ?? Arr::get($meta, 'bio'),
            'bio' => Arr::get($meta, 'instagram_bio') ?? Arr::get($meta, 'bio'),
            'company' => Arr::get($meta, 'company_name') ?? Arr::get($meta, 'company'),
            'profile_url' => $lead->profile_url,
            'scrape_url' => $scrapeUrl,
        ]);

        $company = trim((string) (Arr::get($meta, 'company_name') ?? Arr::get($meta, 'company', '')));
        if ($company === '') {
            $companyCandidates = $this->companySearch->extractCompanyNames(
                trim((string) ($lead->full_name ?? '')).' '.trim((string) ($lead->headline ?? '')),
                null,
            );
            $company = trim((string) ($companyCandidates[0] ?? ''));
        }
        if ($company !== '') {
            $companyResearch = $this->companySearch->research($company);
            if ($companyResearch['ok'] ?? false) {
                $excerpt = trim((string) ($companyResearch['excerpt'] ?? ''));
                if ($excerpt !== '') {
                    $intel['signals'] = array_values(array_unique([
                        ...($intel['signals'] ?? []),
                        ...$this->inferSignals($excerpt, []),
                    ]));
                    $intel['company_research'] = [[
                        'company' => $company,
                        'url' => (string) ($companyResearch['url'] ?? ''),
                        'title' => (string) ($companyResearch['title'] ?? ''),
                        'excerpt' => Str::limit($excerpt, 1200),
                    ]];
                }
            }
        }

        $this->memory->mergeIntelligenceSnapshot($lead, $intel);

        return $intel;
    }

    /**
     * @param  list<array<string,mixed>>  $scraped
     * @return list<string>
     */
    private function inferSignals(string $textBlob, array $scraped): array
    {
        $hay = Str::lower($textBlob.' '.collect($scraped)->pluck('excerpt')->implode(' '));
        $signals = [];

        foreach ([
            'referrals' => ['referral', 'word of mouth', 'word-of-mouth'],
            'content_marketing' => ['content marketing', 'inbound', 'seo', 'blog'],
            'outbound' => ['outbound', 'cold email', 'cold call', 'prospecting'],
            'agency' => ['agency', 'consulting', 'freelance'],
            'saas' => ['saas', 'software', 'b2b'],
        ] as $label => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($hay, $needle)) {
                    $signals[] = $label;
                    break;
                }
            }
        }

        return array_values(array_unique($signals));
    }
}

<?php

namespace App\V2\Ai\Services;

use App\Models\V2Conversation;
use App\Models\V2OutreachLead;
use App\V2\Services\CompanyWebSearchService;
use App\V2\Services\WebScrapeChainService;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Runs on every inbound reply: scrape links, research companies, grow the dossier.
 */
class ProspectIntelligenceService
{
    private const MAX_URL_SCRAPES = 2;

    private const MAX_COMPANY_SEARCHES = 1;

    public function __construct(
        private readonly WebScrapeChainService $scraper,
        private readonly CompanyWebSearchService $companySearch,
        private readonly ProspectMemoryService $memory,
        private readonly ConversionStageService $stages,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function processInbound(V2OutreachLead $lead, V2Conversation $conversation, string $inboundBody): array
    {
        $inboundBody = trim($inboundBody);
        if ($inboundBody === '') {
            return $this->memory->dossier($lead);
        }

        $this->stages->advanceOnInbound($lead);
        $lead = $lead->fresh() ?? $lead;

        $this->extractConversationFacts($lead, $inboundBody);

        $urls = $this->scraper->extractUrls($inboundBody);
        $scraped = 0;
        foreach ($urls as $url) {
            if ($scraped >= self::MAX_URL_SCRAPES) {
                break;
            }
            if ($this->scraper->shouldSkipUrl($url)) {
                continue;
            }

            $result = $this->scraper->scrape($url);
            if (! $result['ok']) {
                continue;
            }

            $this->memory->appendScrapedPage($lead, [
                'url' => $result['url'],
                'title' => $result['title'],
                'excerpt' => $result['content'],
                'source' => $result['source'],
                'signals' => $this->inferSignals($result['content']),
            ], 'inbound_url');

            $scraped++;
            $lead = $lead->fresh() ?? $lead;
        }

        $meta = is_array($lead->meta) ? $lead->meta : [];
        $knownCompany = trim((string) (Arr::get($meta, 'company_name') ?? Arr::get($meta, 'company', '')));
        $companies = $this->companySearch->extractCompanyNames($inboundBody, $knownCompany !== '' ? $knownCompany : null);

        $searched = 0;
        foreach ($companies as $company) {
            if ($searched >= self::MAX_COMPANY_SEARCHES) {
                break;
            }

            $research = $this->companySearch->research($company);
            if (! $research['ok']) {
                continue;
            }

            $this->memory->appendCompanyResearch($lead, $research);
            $searched++;
            $lead = $lead->fresh() ?? $lead;
        }

        return $this->memory->dossier($lead->fresh() ?? $lead);
    }

    private function extractConversationFacts(V2OutreachLead $lead, string $text): void
    {
        $patterns = [
            '/\b(?:we|i)\s+(?:use|using|run|have)\s+([^\.!\?\n]{8,120})/iu',
            '/\b(?:struggle| struggling|challenge|problem|pain)\s+(?:with|is)\s+([^\.!\?\n]{8,120})/iu',
            '/\b(?:looking for|need|want)\s+([^\.!\?\n]{8,120})/iu',
            '/\b(?:budget|spend|paying)\s+([^\.!\?\n]{5,80})/iu',
        ];

        foreach ($patterns as $pattern) {
            if (! preg_match($pattern, $text, $match)) {
                continue;
            }

            $fact = trim($match[0]);
            if ($fact !== '') {
                $this->memory->appendConversationFact($lead, $fact, 'inbound');
            }
        }

        if (strlen($text) >= 40 && strlen($text) <= 280 && ! str_contains($text, 'http')) {
            $this->memory->appendConversationFact($lead, Str::limit($text, 200), 'inbound_message');
        }
    }

    /**
     * @return list<string>
     */
    private function inferSignals(string $text): array
    {
        $hay = Str::lower($text);
        $signals = [];

        foreach ([
            'referrals' => ['referral', 'word of mouth'],
            'outbound' => ['outbound', 'cold email', 'prospecting'],
            'agency' => ['agency', 'consulting'],
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

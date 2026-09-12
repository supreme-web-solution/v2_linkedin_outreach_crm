<?php

namespace App\V2\Ai\Services;

use App\Models\V2Conversation;
use App\Models\V2OutreachLead;
use App\V2\Services\CompanyWebSearchService;
use App\V2\Services\LaravelAiWebResearchService;
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
     * Run before every inbox draft/send — scrape links, extract identity, grow dossier.
     *
     * @return array<string, mixed>
     */
    public function ensureEnrichedForReply(V2OutreachLead $lead, V2Conversation $conversation, string $inboundBody): array
    {
        return $this->processInbound($lead, $conversation, $inboundBody, reprocess: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function processInbound(
        V2OutreachLead $lead,
        V2Conversation $conversation,
        string $inboundBody,
        bool $reprocess = false,
    ): array {
        $inboundBody = trim($inboundBody);
        if ($inboundBody === '') {
            return $this->memory->dossier($lead);
        }

        if (! $reprocess) {
            $this->stages->advanceOnInbound($lead);
            $lead = $lead->fresh() ?? $lead;
        }

        $identity = $this->extractIdentity($inboundBody);
        if ($identity['name'] !== null || $identity['location'] !== null) {
            $this->memory->setIdentity($lead, $identity['name'], $identity['location']);
            $lead = $lead->fresh() ?? $lead;
        }

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

            if (! $reprocess && $this->hasScrapedUrl($lead, $url)) {
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

        if ($this->wantsPersonLookup($inboundBody) && ($identity['name'] ?? null) !== null) {
            $personQuery = trim($identity['name'].($identity['location'] !== null ? ' '.$identity['location'] : ''));
            $personResearch = app(LaravelAiWebResearchService::class)->searchPerson($personQuery);
            if (is_array($personResearch) && ($personResearch['ok'] ?? false)) {
                $this->memory->appendPersonResearch($lead, $personResearch);
                $lead = $lead->fresh() ?? $lead;
            }
        }

        $meta = is_array($lead->meta) ? $lead->meta : [];
        $knownCompany = trim((string) (Arr::get($meta, 'company_name') ?? Arr::get($meta, 'company', '')));
        $companies = $this->companySearch->extractCompanyNames($inboundBody, $knownCompany !== '' ? $knownCompany : null);

        $searched = 0;
        foreach ($companies as $company) {
            if ($searched >= self::MAX_COMPANY_SEARCHES) {
                break;
            }

            if ($this->looksLikeLocation($company)) {
                continue;
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

    /**
     * @return array{name: ?string, location: ?string}
     */
    public function extractIdentity(string $text): array
    {
        $name = null;
        $location = null;

        if (preg_match('/\b(?:i am|i\'m|im|i\s*a\s*m|my name is|this is)\s+([a-z][a-z\s.\'-]{1,50}?)(?:\s+from\b|[,.!?]|$)/iu', $text, $match)) {
            $name = $this->cleanPersonName($match[1]);
        }

        if (preg_match('/\bfrom\s+([a-zA-Z]+(?:\s+[a-zA-Z]+)?)\b/u', $text, $match)) {
            $candidate = trim($match[1]);
            if ($this->looksLikeLocation($candidate)) {
                $location = Str::title($candidate);
            }
        }

        if (preg_match('/\bbased in\s+([A-Z][a-zA-Z\s]{2,40})/iu', $text, $match)) {
            $location = trim($match[1]);
        }

        return ['name' => $name, 'location' => $location];
    }

    private function wantsPersonLookup(string $text): bool
    {
        return (bool) preg_match(
            '/\b(look me up|look up|find me|search for me|you can still look me up|google me)\b/i',
            $text,
        );
    }

    private function hasScrapedUrl(V2OutreachLead $lead, string $url): bool
    {
        $normalized = rtrim(Str::lower($url), '/');
        $dossier = $this->memory->dossier($lead);

        foreach ($dossier['scraped_pages'] ?? [] as $page) {
            if (! is_array($page)) {
                continue;
            }
            $existing = rtrim(Str::lower(trim((string) ($page['url'] ?? ''))), '/');
            $excerpt = trim((string) ($page['excerpt'] ?? ''));
            if ($existing === $normalized && $excerpt !== '') {
                return true;
            }
        }

        return false;
    }

    private function looksLikeLocation(string $value): bool
    {
        $lower = Str::lower(trim($value));

        return in_array($lower, [
            'nigeria', 'kenya', 'ghana', 'south africa', 'usa', 'uk', 'canada', 'india',
            'lagos', 'abuja', 'london', 'texas', 'california',
        ], true);
    }

    private function cleanPersonName(string $name): ?string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        $name = trim($name, " .,;'\"");

        if ($name === '' || strlen($name) < 3) {
            return null;
        }

        if (preg_match('/\b(site|link|email|http|tailor|stuff|message)\b/i', $name)) {
            return null;
        }

        return Str::title($name);
    }

    private function extractConversationFacts(V2OutreachLead $lead, string $text): void
    {
        $patterns = [
            '/\b(?:we|i)\s+(?:use|using|run|have)\s+([^\.!\?\n]{8,120})/iu',
            '/\b(?:struggle| struggling|challenge|problem|pain)\s+(?:with|is)\s+([^\.!\?\n]{8,120})/iu',
            '/\b(?:looking for|need|want)\s+([^\.!\?\n]{8,120})/iu',
            '/\b(?:budget|spend|paying)\s+([^\.!\?\n]{5,80})/iu',
            '/\b(?:tailor(?:ed)?|custom|specific)\s+([^\.!\?\n]{8,120})/iu',
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

        $prose = trim(preg_replace('~https?://[^\s\)\]\"\'<>]+~i', ' ', $text) ?? '');
        $prose = trim(preg_replace('/\s+/', ' ', $prose) ?? '');

        if (strlen($prose) >= 25) {
            $this->memory->appendConversationFact($lead, Str::limit($prose, 280), 'inbound_message');
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

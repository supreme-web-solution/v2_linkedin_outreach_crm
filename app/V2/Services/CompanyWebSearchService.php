<?php

namespace App\V2\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Lightweight company research: guess domain, then DuckDuckGo HTML fallback.
 */
class CompanyWebSearchService
{
    public function __construct(
        private readonly WebScrapeChainService $scraper,
        private readonly LaravelAiWebResearchService $laravelAiWeb,
    ) {}

    /**
     * @return array{ok:bool, company:string, query:string, url:?string, title:?string, excerpt:string, source:?string, error:?string}
     */
    public function research(string $companyName): array
    {
        $companyName = trim($companyName);
        if ($companyName === '' || strlen($companyName) < 2) {
            return $this->failure($companyName, 'Company name is too short.');
        }

        $domainGuess = $this->guessDomain($companyName);
        if ($domainGuess !== '') {
            $scrape = $this->scraper->scrape($domainGuess);
            if ($scrape['ok']) {
                return [
                    'ok' => true,
                    'company' => $companyName,
                    'query' => $companyName,
                    'url' => $scrape['url'],
                    'title' => $scrape['title'],
                    'excerpt' => Str::limit($scrape['content'], 2000),
                    'source' => 'domain_guess:'.$scrape['source'],
                    'error' => null,
                ];
            }
        }

        $aiSearch = $this->laravelAiWeb->searchCompany($companyName);
        if (is_array($aiSearch) && ($aiSearch['ok'] ?? false)) {
            return $aiSearch;
        }

        $searchUrl = $this->firstSearchResultUrl($companyName.' company');
        if ($searchUrl !== null && ! $this->scraper->shouldSkipUrl($searchUrl)) {
            $scrape = $this->scraper->scrape($searchUrl);
            if ($scrape['ok']) {
                return [
                    'ok' => true,
                    'company' => $companyName,
                    'query' => $companyName,
                    'url' => $scrape['url'],
                    'title' => $scrape['title'],
                    'excerpt' => Str::limit($scrape['content'], 2000),
                    'source' => 'web_search:'.$scrape['source'],
                    'error' => null,
                ];
            }
        }

        return $this->failure($companyName, 'Could not find readable company information.');
    }

    /**
     * @return list<string>
     */
    public function extractCompanyNames(string $text, ?string $knownCompany = null): array
    {
        $names = [];

        if (is_string($knownCompany) && trim($knownCompany) !== '') {
            $names[] = trim($knownCompany);
        }

        if (preg_match_all('/\b(?:at|from|with|for)\s+([A-Z][A-Za-z0-9&\.\-\']+(?:\s+[A-Z][A-Za-z0-9&\.\-\']+){0,3})\b/u', $text, $matches)) {
            foreach ($matches[1] as $match) {
                $names[] = trim($match);
            }
        }

        if (preg_match('/\b(?:company|business|firm|agency|startup)\s+(?:is|called|named)\s+([A-Z][A-Za-z0-9&\.\-\']+(?:\s+[A-Z][A-Za-z0-9&\.\-\']+){0,3})/u', $text, $match)) {
            $names[] = trim($match[1]);
        }

        $filtered = [];
        foreach ($names as $name) {
            $name = trim($name, " .,;'");
            if ($name === '' || strlen($name) < 2) {
                continue;
            }
            if (in_array(Str::lower($name), ['i', 'we', 'my', 'our', 'the', 'a', 'an'], true)) {
                continue;
            }
            $filtered[] = $name;
        }

        return array_values(array_unique($filtered));
    }

    private function guessDomain(string $companyName): string
    {
        $slug = Str::slug($companyName, '');
        if ($slug === '') {
            return '';
        }

        return 'https://'.$slug.'.com';
    }

    private function firstSearchResultUrl(string $query): ?string
    {
        try {
            $response = Http::timeout(20)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; SociFusionBot/1.0)',
                ])
                ->asForm()
                ->post('https://html.duckduckgo.com/html/', [
                    'q' => $query,
                ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        if (! $response->ok()) {
            Log::warning('[CompanyWebSearch] DuckDuckGo search failed', [
                'status' => $response->status(),
            ]);

            return null;
        }

        $html = $response->body();
        if ($html === '') {
            return null;
        }

        if (preg_match('/class="result__a"[^>]*href="([^"]+)"/i', $html, $match)) {
            $href = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (str_starts_with($href, '//')) {
                $href = 'https:'.$href;
            }

            return filter_var($href, FILTER_VALIDATE_URL) ? $href : null;
        }

        return null;
    }

    /**
     * @return array{ok:bool, company:string, query:string, url:?string, title:?string, excerpt:string, source:?string, error:?string}
     */
    private function failure(string $company, string $error): array
    {
        return [
            'ok' => false,
            'company' => $company,
            'query' => $company,
            'url' => null,
            'title' => null,
            'excerpt' => '',
            'source' => null,
            'error' => $error,
        ];
    }
}

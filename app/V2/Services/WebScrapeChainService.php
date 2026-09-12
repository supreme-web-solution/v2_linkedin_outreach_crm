<?php

namespace App\V2\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Scrape readable page content: JINA Reader first, then direct HTTP fetch.
 */
class WebScrapeChainService
{
    public function __construct(
        private readonly JinaReaderService $jina,
        private readonly LaravelAiWebResearchService $laravelAiWeb,
    ) {}

    /**
     * @return array{ok:bool, url:string, title:?string, content:string, source:?string, error:?string}
     */
    public function scrape(string $url): array
    {
        $url = $this->normalizeUrl($url);
        if ($url === '') {
            return $this->failure('', 'URL is empty.');
        }

        if ($this->jina->isConfigured()) {
            $jina = $this->jina->scrape($url);
            if ($jina['ok']) {
                return [
                    'ok' => true,
                    'url' => $jina['url'],
                    'title' => $jina['title'],
                    'content' => $jina['content'],
                    'source' => 'jina',
                    'error' => null,
                ];
            }
        }

        $aiFetch = $this->laravelAiWeb->fetchUrl($url);
        if (is_array($aiFetch) && ($aiFetch['ok'] ?? false)) {
            return $aiFetch;
        }

        return $this->httpFetch($url);
    }

    /**
     * @return list<string>
     */
    public function extractUrls(string $text): array
    {
        if ($text === '') {
            return [];
        }

        preg_match_all('~https?://[^\s\)\]\"\'<>]+~i', $text, $matches);

        return array_values(array_unique(array_map(
            fn (string $url) => rtrim($url, '.,;'),
            $matches[0] ?? [],
        )));
    }

    public function shouldSkipUrl(string $url): bool
    {
        $host = Str::lower(parse_url($url, PHP_URL_HOST) ?? '');

        return str_contains($host, 'linkedin.com')
            || str_contains($host, 'instagram.com')
            || str_contains($host, 'facebook.com')
            || str_contains($host, 'twitter.com')
            || str_contains($host, 'x.com')
            || str_contains($host, 'tiktok.com');
    }

    /**
     * @return array{ok:bool, url:string, title:?string, content:string, source:?string, error:?string}
     */
    private function httpFetch(string $url): array
    {
        try {
            $response = Http::timeout(25)
                ->withHeaders(['User-Agent' => 'SociFusionBot/1.0 (+https://socifusion.com)'])
                ->get($url);
        } catch (\Throwable $e) {
            if ($e instanceof \Illuminate\Http\Client\ConnectionException) {
                Log::info('[WebScrapeChain] Could not reach host', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
            } else {
                report($e);
            }

            return $this->failure($url, 'Could not fetch that page.');
        }

        if (! $response->ok()) {
            Log::warning('[WebScrapeChain] HTTP fetch failed', [
                'url' => $url,
                'status' => $response->status(),
            ]);

            return $this->failure($url, 'HTTP fetch failed (status '.$response->status().').');
        }

        $html = $response->body();
        if ($html === '') {
            return $this->failure($url, 'Page returned empty content.');
        }

        $title = null;
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
            $title = trim(html_entity_decode(strip_tags($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        $text = $this->htmlToText($html);
        if ($text === '') {
            return $this->failure($url, 'Could not extract readable text.');
        }

        return [
            'ok' => true,
            'url' => $url,
            'title' => $title,
            'content' => Str::limit($text, 12000),
            'source' => 'http',
            'error' => null,
        ];
    }

    private function htmlToText(string $html): string
    {
        $html = preg_replace('/<(script|style|noscript)[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
                $url = 'https://'.ltrim($url, '/');
            }
        }

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
    }

    /**
     * @return array{ok:bool, url:string, title:?string, content:string, source:?string, error:?string}
     */
    private function failure(string $url, string $error): array
    {
        return [
            'ok' => false,
            'url' => $url,
            'title' => null,
            'content' => '',
            'source' => null,
            'error' => $error,
        ];
    }
}

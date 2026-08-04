<?php

namespace App\V2\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RapidApiLinkedinService
{
    private const BASE_URL = 'https://fresh-linkedin-profile-data.p.rapidapi.com';

    /** Max RapidAPI pages scanned per Discover click (2 credits each → 4 credits). */
    public const DISCOVERY_MAX_PAGES = 2;

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '';
    }

    /**
     * Search posts by keyword from RapidAPI provider.
     * Scans up to $maxPages (still one user action) and returns every candidate
     * so callers can rank by engagement and keep the best subset.
     *
     * @return array{items: list<array<string, mixed>>, pages_fetched: int, total_reported: int|null}
     */
    public function searchPosts(
        string $keyword,
        int $page = 1,
        int $limit = 100,
        string $datePosted = 'Past month',
        int $maxPages = self::DISCOVERY_MAX_PAGES,
    ): array {
        $maxPages = max(1, min(5, $maxPages));
        $datePosted = $this->normalizeDatePosted($datePosted);

        $items = [];
        $pagesFetched = 0;
        $totalReported = null;
        $startPage = max(1, $page);

        for ($current = $startPage; $current < $startPage + $maxPages; $current++) {
            $payload = [
                'search_keywords' => trim($keyword),
                'sort_by' => 'Top match',
                'content_type' => '',
                'from_member' => [],
                'from_company' => [],
                'mentioning_member' => [],
                'mentioning_company' => [],
                'author_company' => [],
                'author_industry' => [],
                'author_keyword' => '',
                'page' => $current,
            ];

            if ($datePosted !== '') {
                $payload['date_posted'] = $datePosted;
            }

            $response = Http::timeout(35)
                ->withHeaders([
                    'x-rapidapi-key' => $this->apiKey(),
                    'x-rapidapi-host' => 'fresh-linkedin-profile-data.p.rapidapi.com',
                    'Content-Type' => 'application/json',
                ])
                ->post(self::BASE_URL.'/search-posts', $payload);

            $pagesFetched++;

            if (! $response->ok()) {
                Log::warning('[RapidApiLinkedinService] search-posts failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'keyword' => $keyword,
                    'page' => $current,
                ]);

                if ($items === []) {
                    throw new \RuntimeException('RapidAPI request failed: '.$response->body());
                }

                break;
            }

            if ($totalReported === null && is_numeric($response->json('total'))) {
                $totalReported = (int) $response->json('total');
            }

            $rows = $response->json('data', []);
            if (! is_array($rows) || $rows === []) {
                break;
            }

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $items[] = $this->mapPostRow($row);
            }

            // Stop early if this page was empty-ish or we've hit the reported total.
            if (count($rows) < 5) {
                break;
            }
            if ($totalReported !== null && count($items) >= $totalReported) {
                break;
            }
            if (count($items) >= $limit) {
                break;
            }
        }

        return [
            'items' => array_slice($items, 0, max(1, $limit)),
            'pages_fetched' => $pagesFetched,
            'total_reported' => $totalReported,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function mapPostRow(array $row): array
    {
        $post = $row['post'] ?? $row;
        $authorFirst = $post['poster']['first'] ?? '';
        $authorLast = $post['poster']['last'] ?? '';
        $authorName = trim((string) ($post['poster_name'] ?? ($authorFirst.' '.$authorLast)));

        return [
            'post_id' => (string) ($post['urn'] ?? $post['post_id'] ?? ''),
            'author_name' => $authorName !== '' ? $authorName : 'Unknown',
            'author_headline' => (string) ($post['poster_title'] ?? $post['poster']['headline'] ?? ''),
            'author_profile_url' => (string) ($post['poster_linkedin_url'] ?? ''),
            'post_url' => (string) ($post['post_url'] ?? ''),
            'content' => (string) ($post['text'] ?? ''),
            'likes' => (int) ($post['num_likes'] ?? 0),
            'comments' => (int) ($post['num_comments'] ?? 0),
            'shares' => (int) ($post['num_shares'] ?? 0),
            'views' => (int) ($post['num_views'] ?? 0),
            'posted' => (string) ($post['posted'] ?? ''),
            'images' => is_array($post['images'] ?? null) ? $post['images'] : [],
            'video' => $post['video'] ?? null,
        ];
    }

    private function normalizeDatePosted(string $datePosted): string
    {
        return match ($datePosted) {
            'Past year', 'past year' => 'past-year',
            default => $datePosted,
        };
    }

    private function apiKey(): string
    {
        return (string) config('services.rapidapi.key', '');
    }
}

<?php

namespace App\V2\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RapidApiLinkedinService
{
    private const BASE_URL = 'https://fresh-linkedin-profile-data.p.rapidapi.com';

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '';
    }

    /**
     * Search posts by keyword from RapidAPI provider.
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchPosts(string $keyword, int $page = 1, int $limit = 18, string $datePosted = 'Past month'): array
    {
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
            'page' => max(1, $page),
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

        if (!$response->ok()) {
            Log::warning('[RapidApiLinkedinService] search-posts failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'keyword' => $keyword,
            ]);
            throw new \RuntimeException('RapidAPI request failed: '.$response->body());
        }

        $rows = $response->json('data', []);
        if (!is_array($rows)) {
            return [];
        }

        $rows = array_slice($rows, 0, $limit);

        return array_map(function ($row) {
            $post = $row['post'] ?? $row;
            $authorFirst = $post['poster']['first'] ?? '';
            $authorLast = $post['poster']['last'] ?? '';
            $authorName = trim(($post['poster_name'] ?? ($authorFirst.' '.$authorLast)));

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
        }, $rows);
    }

    private function apiKey(): string
    {
        return (string) config('services.rapidapi.key', '');
    }
}


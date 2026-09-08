<?php

namespace App\V2\Ai\Support;

use Illuminate\Support\Str;

class IcpSearchFilterParser
{
    /**
     * Turn a natural-language sales goal into Unipile people-search filters.
     *
     * @return array{
     *     keywords: string,
     *     title?: string,
     *     location?: string,
     *     limit: int,
     *     audience_name: string,
     *     target_meetings?: int
     * }
     */
    public static function fromGoal(string $query, ?string $geography = null, ?int $fallbackLimit = null): array
    {
        $query = trim($query);
        $lower = Str::lower($query);

        $targetMeetings = null;
        if (preg_match('/\b(\d+)\s+meetings?\b/i', $query, $matches)) {
            $targetMeetings = max(1, (int) $matches[1]);
        }

        $location = trim((string) $geography);
        if ($location === '') {
            if (preg_match('/\b(us|usa|u\.s\.|united states)\b/i', $query)) {
                $location = 'United States';
            } elseif (preg_match('/\b(uk|united kingdom)\b/i', $query)) {
                $location = 'United Kingdom';
            } elseif (preg_match('/\b(europe|eu)\b/i', $query)) {
                $location = 'Europe';
            }
        }

        $title = null;
        if (preg_match('/\bfounders?\b/i', $query)) {
            $title = 'Founder';
        } elseif (preg_match('/\bceo\b/i', $query)) {
            $title = 'CEO';
        } elseif (preg_match('/\bvp\b|\bvice president\b/i', $query)) {
            $title = 'VP';
        } elseif (preg_match('/\bdirector\b/i', $query)) {
            $title = 'Director';
        }

        $keywords = preg_replace(
            '/\b(book|get|find|meetings?|this month|this quarter|with|for|from|target|goal|need|want|us|usa)\b/i',
            ' ',
            $query,
        ) ?? $query;
        $keywords = trim(preg_replace('/\s+/', ' ', $keywords) ?? '');

        if ($keywords === '') {
            $keywords = $query;
        }

        $limit = $fallbackLimit ?? 50;
        if ($targetMeetings !== null) {
            // Keep per-search modest — LinkedIn/Unipile rate limits; grow via repeat discover.
            $limit = min(100, max(25, $targetMeetings * 5));
        }

        $filters = [
            'keywords' => $keywords,
            'limit' => max(10, min(100, $limit)),
            'audience_name' => Str::limit($query, 80, ''),
        ];

        if ($title !== null) {
            $filters['title'] = $title;
        }

        if ($location !== '') {
            $filters['location'] = $location;
        }

        if ($targetMeetings !== null) {
            $filters['target_meetings'] = $targetMeetings;
        }

        return $filters;
    }
}

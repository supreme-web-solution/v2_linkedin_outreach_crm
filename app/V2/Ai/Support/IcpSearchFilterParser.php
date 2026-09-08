<?php

namespace App\V2\Ai\Support;

use Illuminate\Support\Str;

/**
 * Turn Alex goals / ICP prose into Unipile classic people-search filters.
 * Long product pitches must NOT be sent as LinkedIn keywords.
 */
class IcpSearchFilterParser
{
    /**
     * @return array{
     *     keywords: string,
     *     title?: string,
     *     location?: string,
     *     limit: int,
     *     audience_name: string,
     *     target_meetings?: int,
     *     icp_summary: string
     * }
     */
    public static function fromGoal(string $query, ?string $geography = null, ?int $fallbackLimit = null): array
    {
        $variants = self::searchVariants($query, $geography, $fallbackLimit);

        return $variants[0];
    }

    /**
     * Ordered search attempts: specific → broader. First non-empty Unipile result wins.
     *
     * @return list<array{
     *     keywords: string,
     *     title?: string,
     *     location?: string,
     *     limit: int,
     *     audience_name: string,
     *     target_meetings?: int,
     *     icp_summary: string
     * }>
     */
    public static function searchVariants(string $query, ?string $geography = null, ?int $fallbackLimit = null): array
    {
        $raw = trim($query);
        $icp = self::extractIcpSegment($raw);
        $lower = Str::lower($icp);

        $targetMeetings = null;
        if (preg_match('/\b(\d+)\s+meetings?\b/i', $raw, $matches)) {
            $targetMeetings = max(1, (int) $matches[1]);
        }

        $location = trim((string) $geography);
        if ($location === '') {
            if (preg_match('/\b(us|usa|u\.s\.|united states)\b/i', $raw.$icp)) {
                $location = 'United States';
            } elseif (preg_match('/\b(uk|united kingdom)\b/i', $raw.$icp)) {
                $location = 'United Kingdom';
            } elseif (preg_match('/\bnigeria\b/i', $raw.$icp)) {
                $location = 'Nigeria';
            } elseif (preg_match('/\b(europe|eu)\b/i', $raw.$icp)) {
                $location = 'Europe';
            }
        }

        $titles = self::extractTitles($lower);
        $industries = self::extractIndustries($lower);

        $limit = $fallbackLimit ?? 50;
        if ($targetMeetings !== null) {
            $limit = min(100, max(25, $targetMeetings * 5));
        }
        $limit = max(10, min(100, $limit));

        $audienceName = Str::limit($industries !== []
            ? implode(' ', array_slice($industries, 0, 4)).($titles[0] ?? ' leaders')
            : ($icp !== '' ? $icp : 'LinkedIn Search'), 80, '');

        $primaryKeywords = self::buildKeywords($industries, $titles, $icp);
        $variants = [];

        // 1) Best: industry keywords + primary title
        $variants[] = self::pack($primaryKeywords, $titles[0] ?? null, $location, $limit, $audienceName, $targetMeetings, $icp);

        // 2) Same keywords, no title (title filters are often too strict on classic search)
        if (($titles[0] ?? null) !== null) {
            $variants[] = self::pack($primaryKeywords, null, $location, $limit, $audienceName, $targetMeetings, $icp);
        }

        // 3) Broader industry-only
        if (count($industries) >= 1) {
            $broad = implode(' ', array_slice($industries, 0, 2));
            $variants[] = self::pack($broad, $titles[0] ?? null, $location, $limit, $audienceName, $targetMeetings, $icp);
            $variants[] = self::pack($broad, null, $location, $limit, $audienceName, $targetMeetings, $icp);
        }

        // 4) Role-focused fallbacks that almost always return people
        foreach (['B2B SaaS', 'sales agency', 'lead generation', 'outbound sales'] as $fallbackKw) {
            $variants[] = self::pack($fallbackKw, 'Founder', $location, $limit, $audienceName, $targetMeetings, $icp);
            $variants[] = self::pack($fallbackKw, null, $location, $limit, $audienceName, $targetMeetings, $icp);
        }

        // Dedupe identical filter sets
        $seen = [];
        $unique = [];
        foreach ($variants as $variant) {
            $key = ($variant['keywords'] ?? '').'|'.($variant['title'] ?? '').'|'.($variant['location'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $variant;
        }

        return $unique !== [] ? $unique : [
            self::pack('B2B sales', 'Founder', $location, $limit, 'B2B sales leaders', $targetMeetings, $icp),
        ];
    }

    /**
     * Keep the buyer ICP; drop SociFusion pitch / positioning prose.
     */
    public static function extractIcpSegment(string $query): string
    {
        $query = trim($query);
        if ($query === '') {
            return '';
        }

        $cut = preg_split(
            '/\b(position\s+socifusion|soci\s*fusion\s+as|sell\s+outcomes|as\s+the\s+linkedin|command\s+center\s+that|prevents?\s+missed|without\s+adding\s+sdr)\b/i',
            $query,
            2,
        );
        $head = trim((string) ($cut[0] ?? $query));

        // Prefer the "with …" / "for …" audience clause when present.
        if (preg_match('/\b(?:with|for|targeting)\s+(.+)$/i', $head, $m)) {
            $head = trim($m[1]);
        }

        $head = preg_replace(
            '/\b(book|get|find|schedule|15[-\s]?minute|demos?|meetings?|this month|this quarter|high[-\s]?fit|ideal|prospects?|target|goal|need|want|quick)\b/i',
            ' ',
            $head,
        ) ?? $head;

        $head = trim(preg_replace('/\s+/', ' ', $head) ?? '');

        return $head !== '' ? $head : $query;
    }

    /**
     * @return list<string>
     */
    private static function extractTitles(string $lower): array
    {
        $titles = [];
        $map = [
            'founder' => 'Founder',
            'co-founder' => 'Founder',
            'owner' => 'Owner',
            'ceo' => 'CEO',
            'head of sales' => 'Head of Sales',
            'sales leader' => 'Head of Sales',
            'growth leader' => 'Head of Growth',
            'head of growth' => 'Head of Growth',
            'vp sales' => 'VP Sales',
            'vice president of sales' => 'VP Sales',
            'director of sales' => 'Director of Sales',
            'sales director' => 'Director of Sales',
            'sdr' => 'SDR',
            'agency owner' => 'Owner',
        ];

        foreach ($map as $needle => $title) {
            if (str_contains($lower, $needle) && ! in_array($title, $titles, true)) {
                $titles[] = $title;
            }
        }

        return $titles;
    }

    /**
     * @return list<string>
     */
    private static function extractIndustries(string $lower): array
    {
        $out = [];
        $map = [
            'b2b' => 'B2B',
            'saas' => 'SaaS',
            'lead-gen' => 'lead generation',
            'lead gen' => 'lead generation',
            'lead generation' => 'lead generation',
            'demand-gen' => 'demand generation',
            'demand gen' => 'demand generation',
            'outbound' => 'outbound',
            'sales consultancy' => 'sales consultancy',
            'sales agency' => 'sales agency',
            'boutique sales' => 'sales agency',
            'agency' => 'agency',
            'agencies' => 'agency',
        ];

        foreach ($map as $needle => $label) {
            if (str_contains($lower, $needle) && ! in_array($label, $out, true)) {
                $out[] = $label;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $industries
     * @param  list<string>  $titles
     */
    private static function buildKeywords(array $industries, array $titles, string $icp): string
    {
        $parts = [];
        foreach (array_slice($industries, 0, 3) as $industry) {
            $parts[] = $industry;
        }

        // Put role words into keywords when we will not also send a title filter,
        // or as soft signal ("founder" in keywords is ok).
        if ($titles !== [] && $industries === []) {
            $parts[] = Str::lower($titles[0]);
        }

        $keywords = trim(implode(' ', $parts));
        if ($keywords !== '') {
            return Str::limit($keywords, 80, '');
        }

        // Last resort: cleaned ICP words only (short).
        $clean = preg_replace('/[^a-zA-Z0-9\s]/', ' ', $icp) ?? $icp;
        $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? '');
        $words = array_values(array_filter(
            explode(' ', $clean),
            fn (string $w) => strlen($w) >= 3 && ! in_array(Str::lower($w), [
                'with', 'and', 'the', 'for', 'from', 'that', 'this', 'plus', 'also', 'their', 'your',
            ], true),
        ));

        $keywords = implode(' ', array_slice($words, 0, 6));

        return $keywords !== '' ? Str::limit($keywords, 80, '') : 'B2B sales';
    }

    /**
     * @return array{
     *     keywords: string,
     *     title?: string,
     *     location?: string,
     *     limit: int,
     *     audience_name: string,
     *     target_meetings?: int,
     *     icp_summary: string
     * }
     */
    private static function pack(
        string $keywords,
        ?string $title,
        string $location,
        int $limit,
        string $audienceName,
        ?int $targetMeetings,
        string $icp,
    ): array {
        $filters = [
            'keywords' => trim($keywords) !== '' ? trim($keywords) : 'B2B sales',
            'limit' => $limit,
            'audience_name' => $audienceName !== '' ? $audienceName : 'LinkedIn Search',
            'icp_summary' => Str::limit($icp, 120, ''),
        ];

        if ($title !== null && $title !== '') {
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

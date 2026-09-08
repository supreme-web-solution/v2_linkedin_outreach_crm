<?php

namespace App\V2\Ai\Support;

use Illuminate\Support\Str;

/**
 * Turn Alex goals / ICP prose into Unipile classic people-search filters.
 * Long product pitches must NOT be sent as LinkedIn keywords.
 *
 * Unipile classic filters we support: keywords, title, location, current_company,
 * past_company, school, network_depths (F/S/O), open_link, limit.
 */
class IcpSearchFilterParser
{
    /**
     * @return array<string, mixed>
     */
    public static function fromGoal(string $query, ?string $geography = null, ?int $fallbackLimit = null): array
    {
        $variants = self::searchVariants($query, $geography, $fallbackLimit);

        return $variants[0];
    }

    /**
     * Ordered search attempts: specific → broader. First non-empty Unipile result wins.
     *
     * @param  array<string, mixed>  $explicit  Optional overrides from discover_prospects / plan
     * @return list<array<string, mixed>>
     */
    public static function searchVariants(
        string $query,
        ?string $geography = null,
        ?int $fallbackLimit = null,
        array $explicit = [],
    ): array {
        $raw = trim($query);
        $icp = self::extractIcpSegment($raw);
        $lower = Str::lower($icp.' '.$raw);

        $targetMeetings = null;
        if (preg_match('/\b(\d+)\s+meetings?\b/i', $raw, $matches)) {
            $targetMeetings = max(1, (int) $matches[1]);
        }

        $location = trim((string) ($explicit['geography'] ?? $explicit['location'] ?? $geography ?? ''));
        if ($location === '') {
            $location = self::extractLocation($raw.' '.$icp);
        }

        $titles = self::extractTitles($lower);
        if (! empty($explicit['title'])) {
            $titles = [trim((string) $explicit['title']), ...$titles];
            $titles = array_values(array_unique($titles));
        }

        $industries = self::extractIndustries($lower);
        $networkDepths = self::normalizeNetworkDepths(
            $explicit['network_depths'] ?? $explicit['network_degree'] ?? null,
            $raw.' '.$icp,
        );
        $openLink = array_key_exists('open_link', $explicit)
            ? (bool) $explicit['open_link']
            : self::extractOpenLink($lower);
        $currentCompany = trim((string) ($explicit['current_company'] ?? $explicit['company'] ?? ''));
        if ($currentCompany === '') {
            $currentCompany = self::extractCompany($raw) ?? '';
        }
        $pastCompany = trim((string) ($explicit['past_company'] ?? ''));
        $school = trim((string) ($explicit['school'] ?? ''));

        $limit = $fallbackLimit ?? (isset($explicit['limit']) ? (int) $explicit['limit'] : 50);
        if ($targetMeetings !== null) {
            $limit = min(100, max(25, $targetMeetings * 5));
        }
        $limit = max(10, min(100, $limit));

        $audienceName = trim((string) ($explicit['audience_name'] ?? ''));
        if ($audienceName === '') {
            $audienceName = Str::limit($industries !== []
                ? implode(' ', array_slice($industries, 0, 4)).($titles[0] ?? ' leaders')
                : ($icp !== '' ? $icp : 'LinkedIn Search'), 80, '');
            if ($networkDepths === ['F']) {
                $audienceName = Str::limit('1st° '.$audienceName, 80, '');
            } elseif ($networkDepths === ['S']) {
                $audienceName = Str::limit('2nd° '.$audienceName, 80, '');
            } elseif ($networkDepths === ['O']) {
                $audienceName = Str::limit('3rd°+ '.$audienceName, 80, '');
            }
        }

        $primaryKeywords = self::buildKeywords($industries, $titles, $icp);
        $extras = [
            'network_depths' => $networkDepths,
            'open_link' => $openLink,
            'current_company' => $currentCompany !== '' ? $currentCompany : null,
            'past_company' => $pastCompany !== '' ? $pastCompany : null,
            'school' => $school !== '' ? $school : null,
        ];

        $variants = [];
        $variants[] = self::pack($primaryKeywords, $titles[0] ?? null, $location, $limit, $audienceName, $targetMeetings, $icp, $extras);

        if (($titles[0] ?? null) !== null) {
            $variants[] = self::pack($primaryKeywords, null, $location, $limit, $audienceName, $targetMeetings, $icp, $extras);
        }

        if (count($industries) >= 1) {
            $broad = implode(' ', array_slice($industries, 0, 2));
            $variants[] = self::pack($broad, $titles[0] ?? null, $location, $limit, $audienceName, $targetMeetings, $icp, $extras);
            $variants[] = self::pack($broad, null, $location, $limit, $audienceName, $targetMeetings, $icp, $extras);
        }

        foreach (['B2B SaaS', 'sales agency', 'lead generation', 'outbound sales'] as $fallbackKw) {
            $variants[] = self::pack($fallbackKw, 'Founder', $location, $limit, $audienceName, $targetMeetings, $icp, $extras);
            $variants[] = self::pack($fallbackKw, null, $location, $limit, $audienceName, $targetMeetings, $icp, $extras);
        }

        $seen = [];
        $unique = [];
        foreach ($variants as $variant) {
            $key = ($variant['keywords'] ?? '').'|'.($variant['title'] ?? '').'|'.($variant['location'] ?? '')
                .'|'.implode(',', $variant['network_depths'] ?? []).'|'.($variant['current_company'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $variant;
        }

        return $unique !== [] ? $unique : [
            self::pack('B2B sales', 'Founder', $location, $limit, 'B2B sales leaders', $targetMeetings, $icp, $extras),
        ];
    }

    /**
     * Unipile classic: F=1st, S=2nd, O=3rd+.
     *
     * @return list<string>|null
     */
    public static function normalizeNetworkDepths(mixed $explicit, string $prose = ''): ?array
    {
        $fromExplicit = self::coerceNetworkDepths($explicit);
        if ($fromExplicit !== null) {
            return $fromExplicit;
        }

        $lower = Str::lower($prose);
        $depths = [];

        if (preg_match('/\b(1st|first)([\s-]?degree)?\b|\bonly\s+(my\s+)?connections\b|\balready\s+connected\b/i', $lower)) {
            $depths[] = 'F';
        }
        if (preg_match('/\b(2nd|second)([\s-]?degree)?\b/i', $lower)) {
            $depths[] = 'S';
        }
        if (preg_match('/\b(3rd|third)([\s-]?degree)?\+?\b|\bout\s+of\s+network\b/i', $lower)) {
            $depths[] = 'O';
        }

        if ($depths === [] && preg_match('/\bnot\s+(1st|first)\b/i', $lower)) {
            $depths = ['S', 'O'];
        }

        $depths = array_values(array_unique($depths));

        return $depths !== [] ? $depths : null;
    }

    /**
     * @return list<string>|null
     */
    private static function coerceNetworkDepths(mixed $value): ?array
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        $parts = is_array($value) ? $value : (preg_split('/[,\s+|]+/', (string) $value) ?: []);
        $out = [];
        foreach ($parts as $part) {
            $p = Str::lower(trim((string) $part));
            if ($p === '') {
                continue;
            }
            $mapped = match (true) {
                in_array($p, ['f', '1', '1st', 'first', 'first_degree', 'first-degree', 'degree_1', 'distance_1'], true) => 'F',
                in_array($p, ['s', '2', '2nd', 'second', 'second_degree', 'second-degree', 'degree_2', 'distance_2'], true) => 'S',
                in_array($p, ['o', '3', '3rd', 'third', 'third_degree', 'third-degree', '3rd+', 'degree_3', 'distance_3'], true) => 'O',
                default => null,
            };
            if ($mapped !== null) {
                $out[] = $mapped;
            }
        }

        $out = array_values(array_unique($out));

        return $out !== [] ? $out : null;
    }

    public static function isFirstDegreeOnly(?array $networkDepths): bool
    {
        return is_array($networkDepths) && $networkDepths === ['F'];
    }

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

    private static function extractLocation(string $text): string
    {
        $map = [
            '/\b(us|usa|u\.s\.|united states|america)\b/i' => 'United States',
            '/\b(uk|united kingdom|england|britain)\b/i' => 'United Kingdom',
            '/\bnigeria\b/i' => 'Nigeria',
            '/\b(canada)\b/i' => 'Canada',
            '/\b(australia)\b/i' => 'Australia',
            '/\b(germany|deutschland)\b/i' => 'Germany',
            '/\b(france)\b/i' => 'France',
            '/\b(india)\b/i' => 'India',
            '/\b(netherlands|holland)\b/i' => 'Netherlands',
            '/\b(uae|dubai|united arab emirates)\b/i' => 'United Arab Emirates',
            '/\b(south africa)\b/i' => 'South Africa',
            '/\b(singapore)\b/i' => 'Singapore',
            '/\b(ireland)\b/i' => 'Ireland',
            '/\b(europe|eu)\b/i' => 'Europe',
            '/\b(lagos)\b/i' => 'Lagos',
            '/\b(london)\b/i' => 'London',
            '/\b(new york|nyc)\b/i' => 'New York',
            '/\b(san francisco|sf bay)\b/i' => 'San Francisco',
        ];

        foreach ($map as $pattern => $label) {
            if (preg_match($pattern, $text)) {
                return $label;
            }
        }

        return '';
    }

    private static function extractOpenLink(string $lower): ?bool
    {
        if (preg_match('/\bopen\s+to\s+connect|open\s+link|openlink\b/i', $lower)) {
            return true;
        }

        return null;
    }

    private static function extractCompany(string $text): ?string
    {
        if (preg_match('/\b(?:at|from|company)\s+([A-Z][A-Za-z0-9&.\'\-\s]{1,40})/u', $text, $m)) {
            $company = trim($m[1]);
            if (! preg_match('/\b(Founder|CEO|SaaS|B2B|United|LinkedIn)\b/i', $company)) {
                return Str::limit($company, 80, '');
            }
        }

        return null;
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

        if ($titles !== [] && $industries === []) {
            $parts[] = Str::lower($titles[0]);
        }

        $keywords = trim(implode(' ', $parts));
        if ($keywords !== '') {
            return Str::limit($keywords, 80, '');
        }

        $clean = preg_replace('/[^a-zA-Z0-9\s]/', ' ', $icp) ?? $icp;
        $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? '');
        $words = array_values(array_filter(
            explode(' ', $clean),
            fn (string $w) => strlen($w) >= 3 && ! in_array(Str::lower($w), [
                'with', 'and', 'the', 'for', 'from', 'that', 'this', 'plus', 'also', 'their', 'your',
                'first', 'second', 'third', 'degree', '1st', '2nd', '3rd',
            ], true),
        ));

        $keywords = implode(' ', array_slice($words, 0, 6));

        return $keywords !== '' ? Str::limit($keywords, 80, '') : 'B2B sales';
    }

    /**
     * @param  array<string, mixed>  $extras
     * @return array<string, mixed>
     */
    private static function pack(
        string $keywords,
        ?string $title,
        string $location,
        int $limit,
        string $audienceName,
        ?int $targetMeetings,
        string $icp,
        array $extras = [],
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

        if (! empty($extras['network_depths']) && is_array($extras['network_depths'])) {
            $filters['network_depths'] = array_values($extras['network_depths']);
        }

        if (array_key_exists('open_link', $extras) && $extras['open_link'] !== null) {
            $filters['open_link'] = (bool) $extras['open_link'];
        }

        foreach (['current_company', 'past_company', 'school'] as $key) {
            if (! empty($extras[$key])) {
                $filters[$key] = (string) $extras[$key];
            }
        }

        return $filters;
    }
}

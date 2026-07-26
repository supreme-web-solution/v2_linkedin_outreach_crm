<?php

namespace App\V2\Services;

use App\Models\Audience;
use App\Models\AudienceList;
use App\Models\V2IntegrationAccount;
use App\V2\Integrations\ProviderManager;
use App\V2\Integrations\Unipile\UnipileProvider;
use Illuminate\Support\Arr;

class CompetitorEngagerHarvestService
{
    public function __construct(private readonly ProviderManager $providers)
    {
    }

    /**
     * @return array{stored_count: int, posts_scanned: int, total_fetched: int}
     */
    public function harvest(Audience $audience, int $userId, string $companyUrl): array
    {
        $accountId = V2IntegrationAccount::activeUnipileAccountId($userId);
        if (! $accountId) {
            throw new \RuntimeException('Connect LinkedIn via Integrations before harvesting.');
        }

        /** @var UnipileProvider $provider */
        $provider = $this->providers->get('unipile', UnipileProvider::class);
        $context = ['account_id' => $accountId];

        $company = $this->resolveCompany($provider, $companyUrl, $context);

        $postsLimit = max(1, (int) config('services.competitor_followers.company_posts_limit', 15));
        $pageSize = max(1, min(100, (int) config('services.competitor_followers.page_size', 100)));
        $maxEngagersPerPost = max(50, (int) config('services.competitor_followers.max_engagers_per_post', 500));

        $meta = json_decode((string) $audience->source_meta, true) ?? [];
        $meta['company_id'] = $company['id'] ?? null;
        $meta['company_name'] = $company['name'] ?? null;
        $audience->source_meta = json_encode($meta);
        $audience->save();

        $this->updateProgress($audience, 'processing', 'Loading recent company posts…');

        $posts = $this->fetchCompanyPosts($provider, (string) $company['id'], $postsLimit, $pageSize, $context);
        if ($posts === []) {
            throw new \RuntimeException('No posts found for this company. Check the URL and LinkedIn connection.');
        }

        $seen = $this->existingEngagerKeys($audience->audience_id);
        $storedCount = 0;
        $totalFetched = 0;

        foreach ($posts as $index => $post) {
            $postLabel = (string) ($index + 1);
            $socialId = $this->resolvePostSocialId($post);
            if ($socialId === '') {
                continue;
            }

            $this->updateProgress(
                $audience,
                'processing',
                "Scanning post {$postLabel}/".count($posts).' for reactions and comments…',
                ['posts_scanned' => $index, 'stored_count' => $storedCount]
            );

            $reactions = $this->paginateItems(
                fn (?string $cursor) => $provider->listPostReactions($socialId, array_filter([
                    'limit' => $pageSize,
                    'cursor' => $cursor,
                ]), $context),
                $maxEngagersPerPost
            );

            foreach ($reactions as $reaction) {
                if (! is_array($reaction)) {
                    continue;
                }
                $mapped = $this->mapEngager(Arr::get($reaction, 'author', []), 'reaction');
                if ($mapped === null) {
                    continue;
                }
                $totalFetched++;
                if ($this->storeEngager($audience->audience_id, $mapped, $seen)) {
                    $storedCount++;
                }
            }

            $comments = $this->paginateItems(
                fn (?string $cursor) => $provider->listPostComments($socialId, array_filter([
                    'limit' => $pageSize,
                    'cursor' => $cursor,
                ]), $context),
                $maxEngagersPerPost
            );

            foreach ($comments as $comment) {
                if (! is_array($comment)) {
                    continue;
                }
                $author = Arr::get($comment, 'author_details', Arr::get($comment, 'author', Arr::get($comment, 'written_by', [])));
                if (is_string($author)) {
                    $author = Arr::get($comment, 'author_details', []);
                }
                $mapped = $this->mapEngager(is_array($author) ? $author : [], 'comment');
                if ($mapped === null) {
                    continue;
                }
                $totalFetched++;
                if ($this->storeEngager($audience->audience_id, $mapped, $seen)) {
                    $storedCount++;
                }
            }
        }

        return [
            'stored_count' => $storedCount,
            'posts_scanned' => count($posts),
            'total_fetched' => $totalFetched,
        ];
    }

    public function resolveCompanyIdentifier(string $companyUrl): string
    {
        $path = (string) parse_url(trim($companyUrl), PHP_URL_PATH);
        if ($path === '') {
            return '';
        }

        if (preg_match('~/company/([^/?#]+)~i', $path, $matches)) {
            return rawurldecode($matches[1]);
        }

        return '';
    }

    /**
     * @return array{id: string, name: string|null, profile_url: string|null}
     */
    public function resolveCompany(UnipileProvider $provider, string $companyUrl, array $context): array
    {
        $slug = $this->resolveCompanyIdentifier($companyUrl);
        if ($slug === '') {
            throw new \InvalidArgumentException('Could not parse a LinkedIn company slug from the URL.');
        }

        $search = $provider->searchCompanies(['keywords' => $slug], $context);
        $items = Arr::get($search, 'items', []);
        if (! is_array($items)) {
            $items = [];
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $profileUrl = rtrim((string) (Arr::get($item, 'profile_url') ?? ''), '/').'/';
            $expected = 'https://www.linkedin.com/company/'.$slug.'/';

            if (strcasecmp($profileUrl, $expected) === 0) {
                return [
                    'id' => (string) (Arr::get($item, 'id') ?? ''),
                    'name' => Arr::get($item, 'name'),
                    'profile_url' => Arr::get($item, 'profile_url'),
                ];
            }
        }

        if (isset($items[0]) && is_array($items[0]) && Arr::get($items[0], 'id')) {
            return [
                'id' => (string) Arr::get($items[0], 'id'),
                'name' => Arr::get($items[0], 'name'),
                'profile_url' => Arr::get($items[0], 'profile_url'),
            ];
        }

        throw new \RuntimeException('Company not found on LinkedIn for URL: '.$companyUrl);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchCompanyPosts(
        UnipileProvider $provider,
        string $companyId,
        int $postsLimit,
        int $pageSize,
        array $context
    ): array {
        $maxScan = max($postsLimit, (int) config('services.competitor_followers.max_posts_scan', 30));
        $collected = [];
        $cursor = null;

        while (count($collected) < $maxScan) {
            $response = $provider->searchPosts(array_filter([
                'from_company' => [$companyId],
                'count' => min($pageSize, $maxScan - count($collected)),
                'cursor' => $cursor,
            ]), $context);

            $items = Arr::get($response, 'items', []);
            if (! is_array($items) || $items === []) {
                break;
            }

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                if ((string) (Arr::get($item, 'type') ?? 'POST') !== 'POST' && ! Arr::has($item, 'social_id')) {
                    continue;
                }
                $collected[] = $item;
                if (count($collected) >= $maxScan) {
                    break 2;
                }
            }

            $cursor = Arr::get($response, 'cursor');
            if (! is_string($cursor) || $cursor === '') {
                break;
            }
        }

        usort($collected, function (array $a, array $b): int {
            $scoreA = (int) Arr::get($a, 'reaction_counter', 0) + (int) Arr::get($a, 'comment_counter', 0);
            $scoreB = (int) Arr::get($b, 'reaction_counter', 0) + (int) Arr::get($b, 'comment_counter', 0);

            return $scoreB <=> $scoreA;
        });

        $engaged = array_values(array_filter(
            $collected,
            fn (array $post) => ((int) Arr::get($post, 'reaction_counter', 0) + (int) Arr::get($post, 'comment_counter', 0)) > 0
        ));

        $pool = $engaged !== [] ? $engaged : $collected;

        return array_slice($pool, 0, $postsLimit);
    }

    /**
     * @param  callable(?string): array<string, mixed>  $fetchPage
     * @return list<array<string, mixed>>
     */
    private function paginateItems(callable $fetchPage, int $maxItems): array
    {
        $items = [];
        $cursor = null;

        while (count($items) < $maxItems) {
            // Pace paginated calls so a harvest doesn't chain hundreds of
            // Unipile requests back-to-back (LinkedIn throttling risk).
            $this->pageDelay();

            $response = $fetchPage($cursor);
            $pageItems = Arr::get($response, 'items', []);
            if (! is_array($pageItems) || $pageItems === []) {
                break;
            }

            foreach ($pageItems as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $items[] = $item;
                if (count($items) >= $maxItems) {
                    break 2;
                }
            }

            $cursor = Arr::get($response, 'cursor');
            if (! is_string($cursor) || $cursor === '') {
                break;
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $post
     */
    private function resolvePostSocialId(array $post): string
    {
        $socialId = trim((string) (Arr::get($post, 'social_id') ?? ''));
        if ($socialId !== '') {
            return $socialId;
        }

        $id = trim((string) (Arr::get($post, 'id') ?? ''));
        if ($id === '') {
            return '';
        }

        if (str_starts_with($id, 'urn:')) {
            return $id;
        }

        return 'urn:li:activity:'.$id;
    }

    /**
     * @param  array<string, mixed>  $author
     * @return array<string, mixed>|null
     */
    private function mapEngager(array $author, string $source): ?array
    {
        if ($author === []) {
            return null;
        }

        $type = strtoupper((string) (Arr::get($author, 'type') ?? ''));
        if ($type === 'COMPANY' || Arr::get($author, 'is_company') === true) {
            return null;
        }

        $providerId = trim((string) (Arr::get($author, 'id') ?? Arr::get($author, 'provider_id') ?? ''));
        $publicId = trim((string) (Arr::get($author, 'public_identifier') ?? ''));
        $profileUrl = trim((string) (Arr::get($author, 'profile_url') ?? ''));

        if ($publicId === '' && preg_match('~linkedin\.com/in/([^/?#]+)~i', $profileUrl, $matches)) {
            $segment = rawurldecode($matches[1]);
            if (! str_starts_with($segment, 'ACo') && ! str_starts_with($segment, 'ADo')) {
                $publicId = $segment;
            }
        }

        if ($providerId === '' && $publicId === '') {
            return null;
        }

        [$firstName, $lastName] = $this->splitName((string) (Arr::get($author, 'name') ?? ''));

        return array_filter([
            'con_id' => $providerId !== '' ? $providerId : null,
            'con_public_identifier' => $publicId !== '' ? $publicId : null,
            'con_first_name' => $firstName,
            'con_last_name' => $lastName,
            'con_job_title' => Arr::get($author, 'headline'),
            'con_profile_url' => $profileUrl !== ''
                ? $profileUrl
                : ($publicId !== '' ? 'https://www.linkedin.com/in/'.$publicId : null),
            'con_distance' => $this->mapNetworkDistance(Arr::get($author, 'network_distance')),
            'con_last_activity' => now(),
            'engagement_source' => $source,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $mapped
     * @param  array<string, true>  $seen
     */
    private function storeEngager(int|string $audienceId, array $mapped, array &$seen): bool
    {
        $key = strtolower((string) ($mapped['con_id'] ?? $mapped['con_public_identifier'] ?? ''));
        if ($key === '' || isset($seen[$key])) {
            return false;
        }

        $seen[$key] = true;

        AudienceList::create([
            'audience_id' => $audienceId,
            'con_first_name' => $mapped['con_first_name'] ?? null,
            'con_last_name' => $mapped['con_last_name'] ?? null,
            'con_job_title' => $mapped['con_job_title'] ?? null,
            'con_public_identifier' => $mapped['con_public_identifier'] ?? null,
            'con_id' => $mapped['con_id'] ?? null,
            'con_profile_url' => $mapped['con_profile_url'] ?? null,
            'con_distance' => $mapped['con_distance'] ?? null,
            'con_last_activity' => $mapped['con_last_activity'] ?? now(),
        ]);

        return true;
    }

    /**
     * @return array<string, true>
     */
    private function pageDelay(): void
    {
        $min = max(0, (int) config('services.unipile_pacing.harvest_page_delay_min_ms', 800));
        $max = max($min, (int) config('services.unipile_pacing.harvest_page_delay_max_ms', 2500));

        if ($max > 0) {
            usleep(random_int($min, $max) * 1000);
        }
    }

    private function existingEngagerKeys(int|string $audienceId): array
    {
        $keys = [];

        AudienceList::query()
            ->where('audience_id', $audienceId)
            ->get(['con_id', 'con_public_identifier'])
            ->each(function (AudienceList $row) use (&$keys) {
                foreach ([$row->con_id, $row->con_public_identifier] as $value) {
                    $value = strtolower(trim((string) $value));
                    if ($value !== '') {
                        $keys[$value] = true;
                    }
                }
            });

        return $keys;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function splitName(string $name): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if ($name === '') {
            return [null, null];
        }

        $parts = explode(' ', $name, 2);

        return [$parts[0], $parts[1] ?? null];
    }

    private function mapNetworkDistance(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return match (strtoupper($value)) {
            'FIRST_DEGREE' => 'DISTANCE_1',
            'SECOND_DEGREE' => 'DISTANCE_2',
            'THIRD_DEGREE' => 'DISTANCE_3',
            default => $value,
        };
    }

    private function updateProgress(Audience $audience, string $status, string $progress, array $extra = []): void
    {
        $meta = json_decode((string) $audience->source_meta, true) ?? [];
        $meta['fetch_status'] = $status;
        $meta['fetch_progress'] = $progress;
        $meta = array_merge($meta, $extra);
        $audience->source_meta = json_encode($meta);
        $audience->save();
    }
}

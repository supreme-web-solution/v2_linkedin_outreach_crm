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
     * Resolve company + select posts, then return social IDs for chunked processing.
     *
     * @return array{post_social_ids: list<string>, company_id: string|null, company_name: string|null}
     */
    public function prepareHarvest(Audience $audience, int $userId, string $companyUrl): array
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

        $postSocialIds = [];
        foreach ($posts as $post) {
            $socialId = $this->resolvePostSocialId($post);
            if ($socialId !== '') {
                $postSocialIds[] = $socialId;
            }
        }

        if ($postSocialIds === []) {
            throw new \RuntimeException('No harvestable posts found for this company.');
        }

        $meta = json_decode((string) $audience->fresh()->source_meta, true) ?? [];
        $meta['post_social_ids'] = $postSocialIds;
        $meta['next_post_index'] = 0;
        $meta['stored_count'] = (int) ($meta['stored_count'] ?? 0);
        $meta['total_fetched'] = (int) ($meta['total_fetched'] ?? 0);
        $meta['posts_scanned'] = 0;
        $audience->source_meta = json_encode($meta);
        $audience->save();

        $this->updateProgress(
            $audience,
            'processing',
            'Queued '.count($postSocialIds).' post(s) for engager scan…',
            ['posts_scanned' => 0, 'stored_count' => (int) ($meta['stored_count'] ?? 0)]
        );

        return [
            'post_social_ids' => $postSocialIds,
            'company_id' => isset($company['id']) ? (string) $company['id'] : null,
            'company_name' => $company['name'] ?? null,
        ];
    }

    /**
     * Harvest reactions + comments for one prepared post, then advance the cursor.
     *
     * @return array{done: bool, stored_count: int, total_fetched: int, posts_scanned: int, next_index: int, total_posts: int}
     */
    public function harvestPreparedPost(Audience $audience, int $userId, int $postIndex): array
    {
        $accountId = V2IntegrationAccount::activeUnipileAccountId($userId);
        if (! $accountId) {
            throw new \RuntimeException('Connect LinkedIn via Integrations before harvesting.');
        }

        $meta = json_decode((string) $audience->source_meta, true) ?? [];
        $postSocialIds = $meta['post_social_ids'] ?? [];
        if (! is_array($postSocialIds) || $postSocialIds === []) {
            throw new \RuntimeException('Harvest posts are not prepared. Restart the competitor pull.');
        }

        $totalPosts = count($postSocialIds);
        if ($postIndex < 0 || $postIndex >= $totalPosts) {
            return [
                'done' => true,
                'stored_count' => (int) ($meta['stored_count'] ?? 0),
                'total_fetched' => (int) ($meta['total_fetched'] ?? 0),
                'posts_scanned' => (int) ($meta['posts_scanned'] ?? $totalPosts),
                'next_index' => $totalPosts,
                'total_posts' => $totalPosts,
            ];
        }

        $socialId = (string) $postSocialIds[$postIndex];
        $pageSize = max(1, min(100, (int) config('services.competitor_followers.page_size', 100)));
        $maxEngagersPerPost = max(50, (int) config('services.competitor_followers.max_engagers_per_post', 500));

        /** @var UnipileProvider $provider */
        $provider = $this->providers->get('unipile', UnipileProvider::class);
        $context = ['account_id' => $accountId];

        $storedCount = (int) ($meta['stored_count'] ?? 0);
        $totalFetched = (int) ($meta['total_fetched'] ?? 0);
        $seen = $this->existingEngagerKeys($audience->audience_id);

        $this->updateProgress(
            $audience,
            'processing',
            'Scanning post '.($postIndex + 1).'/'.$totalPosts.' for reactions and comments…',
            ['posts_scanned' => $postIndex, 'stored_count' => $storedCount]
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
            $author = Arr::get($reaction, 'author', Arr::get($reaction, 'sender', []));
            $mapped = $this->mapEngager(is_array($author) ? $author : [], 'reaction');
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
            [$author, $nameFallback] = $this->extractCommentAuthor($comment);
            $mapped = $this->mapEngager($author, 'comment', $nameFallback);
            if ($mapped === null) {
                continue;
            }
            $totalFetched++;
            if ($this->storeEngager($audience->audience_id, $mapped, $seen)) {
                $storedCount++;
            }
        }

        $nextIndex = $postIndex + 1;
        $done = $nextIndex >= $totalPosts;

        $this->updateProgress(
            $audience,
            $done ? 'processing' : 'processing',
            $done
                ? "Finishing — scanned {$totalPosts} post(s)…"
                : 'Scanning post '.($nextIndex + 1).'/'.$totalPosts.' for reactions and comments…',
            [
                'posts_scanned' => $nextIndex,
                'stored_count' => $storedCount,
                'total_fetched' => $totalFetched,
                'next_post_index' => $nextIndex,
            ]
        );

        return [
            'done' => $done,
            'stored_count' => $storedCount,
            'total_fetched' => $totalFetched,
            'posts_scanned' => $nextIndex,
            'next_index' => $nextIndex,
            'total_posts' => $totalPosts,
        ];
    }

    /**
     * @return array{stored_count: int, posts_scanned: int, total_fetched: int}
     */
    public function harvest(Audience $audience, int $userId, string $companyUrl): array
    {
        $prepared = $this->prepareHarvest($audience, $userId, $companyUrl);
        $storedCount = 0;
        $totalFetched = 0;
        $postsScanned = 0;

        foreach (array_keys($prepared['post_social_ids']) as $index) {
            $result = $this->harvestPreparedPost($audience->fresh(), $userId, $index);
            $storedCount = $result['stored_count'];
            $totalFetched = $result['total_fetched'];
            $postsScanned = $result['posts_scanned'];
        }

        return [
            'stored_count' => $storedCount,
            'posts_scanned' => $postsScanned,
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
     * Unipile comment payloads vary: name may live on string `author` while
     * profile fields live on `author_details` / `written_by` / `sender`.
     *
     * @param  array<string, mixed>  $comment
     * @return array{0: array<string, mixed>, 1: ?string}
     */
    private function extractCommentAuthor(array $comment): array
    {
        $nameFallback = null;
        $rawAuthor = Arr::get($comment, 'author');
        if (is_string($rawAuthor) && trim($rawAuthor) !== '') {
            $nameFallback = trim($rawAuthor);
        }

        $author = Arr::get($comment, 'author_details');
        if (! is_array($author) || $author === []) {
            $author = is_array($rawAuthor) ? $rawAuthor : null;
        }
        if (! is_array($author) || $author === []) {
            $author = Arr::get($comment, 'written_by');
        }
        if (! is_array($author) || $author === []) {
            $author = Arr::get($comment, 'sender');
        }
        if (! is_array($author)) {
            $author = [];
        }

        return [$author, $nameFallback];
    }

    /**
     * @param  array<string, mixed>  $author
     * @return array<string, mixed>|null
     */
    private function mapEngager(array $author, string $source, ?string $nameFallback = null): ?array
    {
        if ($author === [] && ($nameFallback === null || $nameFallback === '')) {
            return null;
        }

        $type = strtoupper((string) (Arr::get($author, 'type') ?? ''));
        if ($type === 'COMPANY' || $type === 'ORGANIZATION' || Arr::get($author, 'is_company') === true) {
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

        [$firstName, $lastName] = $this->resolveAuthorName($author, $nameFallback, $publicId);

        $headline = Arr::get($author, 'headline')
            ?? Arr::get($author, 'description')
            ?? Arr::get($author, 'occupation');

        $networkDistance = Arr::get($author, 'network_distance')
            ?? Arr::get($author, 'specifics.network_distance');

        return array_filter([
            'con_id' => $providerId !== '' ? $providerId : null,
            'con_public_identifier' => $publicId !== '' ? $publicId : null,
            'con_first_name' => $firstName,
            'con_last_name' => $lastName,
            'con_job_title' => is_string($headline) && trim($headline) !== '' ? trim($headline) : null,
            'con_profile_url' => $profileUrl !== ''
                ? $profileUrl
                : ($publicId !== '' ? 'https://www.linkedin.com/in/'.$publicId : null),
            'con_distance' => $this->mapNetworkDistance($networkDistance),
            'con_last_activity' => now(),
            'engagement_source' => $source,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $author
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveAuthorName(array $author, ?string $nameFallback, string $publicId): array
    {
        $full = trim((string) (
            Arr::get($author, 'name')
            ?? Arr::get($author, 'display_name')
            ?? Arr::get($author, 'full_name')
            ?? ''
        ));

        if ($full === '') {
            $first = trim((string) (
                Arr::get($author, 'first_name')
                ?? Arr::get($author, 'firstname')
                ?? Arr::get($author, 'given_name')
                ?? ''
            ));
            $last = trim((string) (
                Arr::get($author, 'last_name')
                ?? Arr::get($author, 'lastname')
                ?? Arr::get($author, 'family_name')
                ?? ''
            ));
            $full = trim($first.' '.$last);
        }

        if ($full === '' && is_string($nameFallback) && trim($nameFallback) !== '') {
            $full = trim($nameFallback);
        }

        if ($full === '' && $publicId !== '') {
            $full = $this->humanizePublicIdentifier($publicId);
        }

        return $this->splitName($full);
    }

    private function humanizePublicIdentifier(string $publicId): string
    {
        $slug = trim($publicId);
        if ($slug === '' || str_starts_with($slug, 'ACo') || str_starts_with($slug, 'ADo')) {
            return '';
        }

        // Drop trailing opaque LinkedIn id segments (e.g. jane-doe-a1b2c3d4).
        $slug = (string) preg_replace('/-[a-z0-9]{6,}$/i', '', $slug);
        $parts = preg_split('/[-_]+/', $slug) ?: [];
        $words = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || ctype_digit($part)) {
                continue;
            }
            $words[] = mb_convert_case($part, MB_CASE_TITLE, 'UTF-8');
        }

        return implode(' ', $words);
    }

    /**
     * @param  array<string, mixed>  $mapped
     * @param  array<string, true>  $seen
     */
    private function storeEngager(int|string $audienceId, array $mapped, array &$seen): bool
    {
        $key = strtolower((string) ($mapped['con_id'] ?? $mapped['con_public_identifier'] ?? ''));
        if ($key === '') {
            return false;
        }

        if (isset($seen[$key])) {
            $this->backfillMissingEngagerFields($audienceId, $mapped);

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
     * Fill blank name/headline on an existing engager when a later API page
     * (or improved mapping) finally provides them.
     *
     * @param  array<string, mixed>  $mapped
     */
    private function backfillMissingEngagerFields(int|string $audienceId, array $mapped): void
    {
        $query = AudienceList::query()->where('audience_id', $audienceId);
        if (! empty($mapped['con_id'])) {
            $query->where('con_id', $mapped['con_id']);
        } elseif (! empty($mapped['con_public_identifier'])) {
            $query->where('con_public_identifier', $mapped['con_public_identifier']);
        } else {
            return;
        }

        /** @var AudienceList|null $row */
        $row = $query->first();
        if (! $row) {
            return;
        }

        $updates = [];
        $existingName = trim(($row->con_first_name ?? '').' '.($row->con_last_name ?? ''));
        if ($existingName === '') {
            if (! empty($mapped['con_first_name'])) {
                $updates['con_first_name'] = $mapped['con_first_name'];
            }
            if (! empty($mapped['con_last_name'])) {
                $updates['con_last_name'] = $mapped['con_last_name'];
            }
        }
        if (trim((string) ($row->con_job_title ?? '')) === '' && ! empty($mapped['con_job_title'])) {
            $updates['con_job_title'] = $mapped['con_job_title'];
        }
        if (trim((string) ($row->con_public_identifier ?? '')) === '' && ! empty($mapped['con_public_identifier'])) {
            $updates['con_public_identifier'] = $mapped['con_public_identifier'];
        }
        if (trim((string) ($row->con_profile_url ?? '')) === '' && ! empty($mapped['con_profile_url'])) {
            $updates['con_profile_url'] = $mapped['con_profile_url'];
        }

        if ($updates !== []) {
            $row->fill($updates)->save();
        }
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

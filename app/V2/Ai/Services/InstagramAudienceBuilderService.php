<?php

namespace App\V2\Ai\Services;

use App\Models\User;
use App\V2\Integrations\Mindcase\MindcaseClient;
use App\V2\Outreach\OutreachImportListService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Find Instagram profiles via Mindcase and save them as outreach import leads
 * (instagram handle filled; other channel columns empty until enriched).
 */
class InstagramAudienceBuilderService
{
    private ?string $lastError = null;

    public function __construct(
        private readonly MindcaseClient $mindcase,
        private readonly OutreachImportListService $imports,
    ) {}

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * @param  list<string>|null  $usernames
     * @return array{
     *     list_hash:string,
     *     list_src:string,
     *     list_name:string,
     *     total_leads:int,
     *     match_score:int,
     *     auto_sourced:bool,
     *     sample_profiles:list<array<string,mixed>>,
     *     platform:string
     * }|null
     */
    public function searchAndPersist(
        User $user,
        string $query,
        ?int $limit = 25,
        ?array $usernames = null,
        ?string $listName = null,
        bool $forceFresh = false,
    ): ?array {
        $this->lastError = null;

        if (! $this->mindcase->configured()) {
            $this->lastError = 'Mindcase is not configured. Add MINDCASE_API_KEY from https://console.mindcase.co';
            Log::info('[Soci] Instagram search skipped — MINDCASE_API_KEY not set', [
                'user_id' => $user->id,
            ]);

            return null;
        }

        $cap = max(1, min($this->mindcase->maxResultsCap(), $limit ?? 25));
        $cacheKey = 'soci:recent-ig-search:'.$user->id;
        if ($forceFresh) {
            Cache::forget($cacheKey);
        }
        $cached = $forceFresh ? null : Cache::get($cacheKey);
        if (is_array($cached) && (int) ($cached['total_leads'] ?? 0) >= min($cap, 1)) {
            Log::info('[Soci] Reusing recent Instagram list — not searching again', [
                'user_id' => $user->id,
                'list_hash' => $cached['list_hash'] ?? null,
            ]);

            return $cached;
        }

        Log::info('[Soci] Instagram Mindcase search starting', [
            'user_id' => $user->id,
            'query' => Str::limit($query, 120),
            'target' => $cap,
        ]);

        $progress = app(WebChatTurnProgressService::class);
        if ($progress->active()) {
            $progress->status('Searching Instagram profiles…');
        }

        $handles = [];
        foreach ($usernames ?? [] as $u) {
            $h = $this->cleanHandle((string) $u);
            if ($h !== '') {
                $handles[] = $h;
            }
        }

        $heartbeat = null;
        if ($progress->active()) {
            $heartbeat = function (int $attempt, int $maxAttempts, string $phase) use ($progress): void {
                if ($attempt === 1 || $attempt % 3 === 0) {
                    $progress->status('Searching Instagram profiles… ('.$attempt.'/'.$maxAttempts.')');
                }
            };
        }

        try {
            $rows = $this->mindcase->instagramProfiles(
                query: $handles === [] ? $query : null,
                usernames: $handles,
                maxResults: $cap,
                heartbeat: $heartbeat,
            );
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            Log::warning('[Soci] Instagram Mindcase search failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($rows === []) {
            $this->lastError = 'No Instagram profiles matched that search. Try a simpler keyword or fewer results.';
            Log::info('[Soci] Instagram Mindcase search finished — no profiles', [
                'user_id' => $user->id,
                'query' => Str::limit($query, 120),
            ]);

            return null;
        }

        Log::info('[Soci] Instagram Mindcase search finished', [
            'user_id' => $user->id,
            'query' => Str::limit($query, 120),
            'profiles' => count($rows),
        ]);

        $contacts = [];
        $samples = [];
        foreach ($rows as $row) {
            $username = $this->cleanHandle((string) (
                Arr::get($row, 'username')
                ?? Arr::get($row, 'handle')
                ?? ''
            ));
            if ($username === '') {
                continue;
            }
            $fullName = trim((string) (Arr::get($row, 'fullName') ?? Arr::get($row, 'full_name') ?? $username));
            $bio = trim((string) (Arr::get($row, 'bio') ?? ''));
            $url = trim((string) (
                Arr::get($row, 'profileUrl')
                ?? Arr::get($row, 'profile_url')
                ?? ('https://www.instagram.com/'.$username)
            ));
            $contacts[] = [
                'full_name' => $fullName !== '' ? $fullName : $username,
                'instagram' => $username,
            ];
            $samples[] = [
                'name' => $fullName !== '' ? $fullName : $username,
                'headline' => $bio !== '' ? Str::limit($bio, 160, '…') : null,
                'username' => $username,
                'profile_url' => $url,
                'followers' => Arr::get($row, 'followers') ?? Arr::get($row, 'followersCount'),
                'platform' => 'instagram',
            ];
        }

        if ($contacts === []) {
            return null;
        }

        $name = $listName !== null && trim($listName) !== ''
            ? trim($listName)
            : Str::limit('IG: '.($query !== '' ? $query : implode(', ', array_slice($handles, 0, 3))), 80, '');

        $result = $this->imports->createFromContactMaps($user, $name.' ('.count($contacts).')', $contacts);

        $saved = [
            'list_hash' => (string) $result['list']['list_hash'],
            'list_src' => 'csv',
            'list_name' => (string) ($result['list']['list_name'] ?? $name),
            'total_leads' => (int) $result['imported'],
            'match_score' => 95,
            'auto_sourced' => true,
            'sample_profiles' => array_slice($samples, 0, 5),
            'platform' => 'instagram',
            'profile_detail' => $samples[0] ?? null,
        ];
        Cache::put($cacheKey, $saved, now()->addMinutes(30));

        return $saved;
    }

    private function cleanHandle(string $value): string
    {
        $value = trim($value);
        if (preg_match('~instagram\.com/([^/?#]+)~i', $value, $m)) {
            $value = $m[1];
        }

        return ltrim($value, '@');
    }
}

<?php

namespace App\V2\Integrations\Mindcase;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Mindcase web-data API client (Instagram profiles, etc.).
 *
 * @see https://mindcase.co/api/instagram/profiles
 * @see https://console.mindcase.co/
 */
class MindcaseClient
{
    public function configured(): bool
    {
        return trim((string) config('services.mindcase.api_key')) !== '';
    }

    public function maxResultsCap(): int
    {
        return max(1, min(100, (int) config('socifusion_ai.max_prospect_pull', 100)));
    }

    /**
     * Search or look up Instagram profiles.
     *
     * @param  list<string>  $usernames
     * @return list<array<string, mixed>>
     */
    public function instagramProfiles(
        ?string $query = null,
        array $usernames = [],
        int $maxResults = 25,
    ): array {
        if (! $this->configured()) {
            throw new RuntimeException(
                'Mindcase is not configured. Add MINDCASE_API_KEY from https://console.mindcase.co'
            );
        }

        $maxResults = max(1, min($this->maxResultsCap(), $maxResults));

        $params = array_filter([
            'query' => $query !== null && trim($query) !== '' ? trim($query) : null,
            'usernames' => $usernames !== [] ? array_values($usernames) : null,
            'maxResults' => $maxResults,
        ], fn ($v) => $v !== null);

        if (! isset($params['query']) && ! isset($params['usernames'])) {
            throw new RuntimeException('Provide a keyword query and/or Instagram usernames.');
        }

        $base = $this->url('/v1/data/instagram/profiles/run');
        $timeout = max(
            (int) config('services.mindcase.timeout', 120),
            min(300, 60 + ($maxResults * 2)),
        );
        $response = Http::withToken((string) config('services.mindcase.api_key'))
            ->timeout($timeout)
            ->acceptJson()
            ->asJson()
            ->post($base.'?wait=true', [
                'params' => $params,
            ]);

        if (! $response->successful()) {
            Log::warning('[Mindcase] Instagram profiles failed', [
                'status' => $response->status(),
                'body' => Str::limit($response->body(), 400),
            ]);
            throw new RuntimeException(
                'Mindcase Instagram lookup failed (HTTP '.$response->status().'): '
                .Str::limit($response->body(), 200)
            );
        }

        $json = $response->json() ?? [];
        if (isset($json['data']) && is_array($json['data'])) {
            return array_values(array_filter($json['data'], 'is_array'));
        }

        $jobId = (string) (Arr::get($json, 'job_id') ?? '');
        if ($jobId === '') {
            return [];
        }

        return $this->pollJobResults($jobId, $maxResults);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pollJobResults(string $jobId, int $maxResults = 25): array
    {
        $sleep = max(1, (int) config('services.mindcase.poll_seconds', 2));
        $baseAttempts = max(1, (int) config('services.mindcase.max_poll_attempts', 90));
        $attempts = max($baseAttempts, (int) ceil($maxResults * 2));

        for ($i = 0; $i < $attempts; $i++) {
            $statusResp = Http::withToken((string) config('services.mindcase.api_key'))
                ->timeout(30)
                ->acceptJson()
                ->get($this->url('/v1/jobs/'.$jobId));

            $status = Str::lower((string) Arr::get($statusResp->json() ?? [], 'status', ''));
            if (in_array($status, ['completed', 'success', 'done'], true)) {
                $results = Http::withToken((string) config('services.mindcase.api_key'))
                    ->timeout(60)
                    ->acceptJson()
                    ->get($this->url('/v1/jobs/'.$jobId.'/results'));

                $data = Arr::get($results->json() ?? [], 'data', []);

                return is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
            }

            if (in_array($status, ['failed', 'error', 'cancelled'], true)) {
                throw new RuntimeException('Mindcase job '.$jobId.' failed with status '.$status);
            }

            sleep($sleep);
        }

        throw new RuntimeException('Mindcase job '.$jobId.' timed out waiting for results.');
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.mindcase.base_url'), '/').'/'.ltrim($path, '/');
    }
}

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
     * HTTP wait timeout for ?wait=true — sized for up to 100 profiles.
     * Production: 50-profile pulls exceeded ~2–3 min; 100 needs more headroom.
     */
    public function httpTimeoutSeconds(int $maxResults): int
    {
        $maxResults = max(1, min($this->maxResultsCap(), $maxResults));
        $configured = max(60, (int) config('services.mindcase.timeout', 300));
        // 100 → 120 + 500 = 620, capped at poll budget below.
        $scaled = 120 + ($maxResults * 5);

        return max($configured, min($this->maxWaitBudgetSeconds(), $scaled));
    }

    /**
     * Async poll attempt count — sized so 100-profile jobs can finish.
     * Default sleep 2s × 300 attempts ≈ 10 minutes.
     */
    public function pollAttempts(int $maxResults): int
    {
        $maxResults = max(1, min($this->maxResultsCap(), $maxResults));
        $base = max(1, (int) config('services.mindcase.max_poll_attempts', 90));
        $sleep = max(1, (int) config('services.mindcase.poll_seconds', 2));
        $budgetSeconds = $this->maxWaitBudgetSeconds();
        $fromBudget = (int) ceil($budgetSeconds / $sleep);
        // 100 → 100 * 3 = 300 attempts @ 2s = 600s
        $scaled = (int) ceil($maxResults * 3);

        return max($base, min($fromBudget, max($scaled, 90)));
    }

    /**
     * Hard ceiling for a single Mindcase Instagram search (wait + poll).
     * Keep under Horizon/workflow job timeouts (typically 900s).
     */
    public function maxWaitBudgetSeconds(): int
    {
        return max(300, min(720, (int) config('services.mindcase.poll_timeout_seconds', 600)));
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
        ?callable $heartbeat = null,
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
        $timeout = $this->httpTimeoutSeconds($maxResults);
        $response = Http::withToken((string) config('services.mindcase.api_key'))
            ->connectTimeout(min(30, $timeout))
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
                'max_results' => $maxResults,
                'http_timeout' => $timeout,
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

        return $this->pollJobResults($jobId, $maxResults, $heartbeat);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pollJobResults(string $jobId, int $maxResults = 25, ?callable $heartbeat = null): array
    {
        $sleep = max(1, (int) config('services.mindcase.poll_seconds', 2));
        $attempts = $this->pollAttempts($maxResults);

        Log::info('[Mindcase] Polling Instagram job', [
            'job_id' => $jobId,
            'max_results' => $maxResults,
            'attempts' => $attempts,
            'poll_seconds' => $sleep,
            'budget_seconds' => $attempts * $sleep,
        ]);

        for ($i = 0; $i < $attempts; $i++) {
            if ($heartbeat !== null) {
                $heartbeat($i + 1, $attempts, 'polling');
            }

            $statusResp = Http::withToken((string) config('services.mindcase.api_key'))
                ->connectTimeout(15)
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

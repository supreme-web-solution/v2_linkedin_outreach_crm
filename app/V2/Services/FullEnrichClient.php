<?php

namespace App\V2\Services;

use App\V2\Enrichment\LeadEnrichmentInput;
use App\V2\Enrichment\LeadEnrichmentResult;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FullEnrichClient
{
    private static bool $creditsExhausted = false;

    public function isConfigured(): bool
    {
        return trim((string) config('services.fullenrich.api_key', '')) !== '';
    }

    public function creditsExhausted(): bool
    {
        return self::$creditsExhausted;
    }

    public static function markCreditsExhausted(): void
    {
        self::$creditsExhausted = true;
    }

    public static function resetCreditsExhausted(): void
    {
        self::$creditsExhausted = false;
    }

    public function enrich(LeadEnrichmentInput $input): LeadEnrichmentResult
    {
        if (! $this->isConfigured() || self::$creditsExhausted || ! $input->needsExternalEnrichment()) {
            return new LeadEnrichmentResult;
        }

        $linkedinUrl = $input->linkedinUrlOrBuild();
        $hasIdentity = ($input->firstName && $input->lastName && ($input->companyDomain || $input->companyName))
            || $linkedinUrl;

        if (! $hasIdentity) {
            return new LeadEnrichmentResult;
        }

        $enrichFields = array_values(array_filter([
            $input->needsEmail() ? 'contact.work_emails' : null,
            $input->needsEmail() ? 'contact.personal_emails' : null,
            $input->needsPhone() ? 'contact.phones' : null,
        ]));

        if ($enrichFields === []) {
            return new LeadEnrichmentResult;
        }

        $payload = [
            'name' => trim(($input->firstName ?? '').' '.($input->lastName ?? '')) ?: 'Lead enrichment',
            'data' => [[
                'first_name' => $input->firstName,
                'last_name' => $input->lastName,
                'domain' => $input->companyDomain,
                'company_name' => $input->companyName,
                'linkedin_url' => $linkedinUrl,
                'enrich_fields' => $enrichFields,
            ]],
        ];

        $startResponse = $this->request('post', '/contact/enrich/bulk', $payload);
        if ($this->isCreditsInsufficientResponse($startResponse)) {
            return new LeadEnrichmentResult;
        }

        $start = $startResponse['data'];
        $enrichmentId = (string) (Arr::get($start, 'enrichment_id') ?? '');
        if ($enrichmentId === '') {
            Log::warning('[FullEnrich] no enrichment_id returned', [
                'http_status' => $startResponse['http_status'],
            ]);

            return new LeadEnrichmentResult;
        }

        $deadline = microtime(true) + (int) config('services.fullenrich.poll_timeout_seconds', 90);
        $interval = (int) config('services.fullenrich.poll_interval_seconds', 3);
        $pollAttempt = 0;

        while (microtime(true) < $deadline) {
            if (self::$creditsExhausted) {
                return new LeadEnrichmentResult;
            }

            sleep(max(1, $interval));
            $pollAttempt++;

            $pollResponse = $this->request('get', '/contact/enrich/bulk/'.$enrichmentId);
            if ($this->isCreditsInsufficientResponse($pollResponse)) {
                return new LeadEnrichmentResult;
            }

            $result = $pollResponse['data'];
            $status = strtoupper((string) (Arr::get($result, 'status') ?? ''));

            if (in_array($status, ['FINISHED', 'COMPLETED', 'DONE'], true)) {
                $parsed = $this->parseContactResult($result, $input);

                Log::info('[FullEnrich] finished', [
                    'enrichment_id' => $enrichmentId,
                    'poll_attempts' => $pollAttempt,
                    'email_found' => ($parsed->email ?? '') !== '',
                    'phone_found' => ($parsed->phone ?? '') !== '',
                ]);

                return $parsed;
            }

            if ($this->isTerminalFailureStatus($status)) {
                Log::warning('[FullEnrich] stopped', [
                    'enrichment_id' => $enrichmentId,
                    'status' => $status,
                ]);

                return new LeadEnrichmentResult;
            }
        }

        Log::warning('[FullEnrich] timed out', [
            'enrichment_id' => $enrichmentId,
            'poll_attempts' => $pollAttempt,
        ]);

        return new LeadEnrichmentResult(timedOut: true);
    }

    /**
     * @param  array{http_status: int, data: array<string, mixed>}  $response
     */
    private function isCreditsInsufficientResponse(array $response): bool
    {
        $status = strtoupper((string) (Arr::get($response['data'], 'status') ?? ''));

        if ($response['http_status'] === 402 || $status === 'CREDITS_INSUFFICIENT') {
            if (! self::$creditsExhausted) {
                Log::warning('[FullEnrich] credits exhausted — further lookups skipped', [
                    'http_status' => $response['http_status'],
                ]);
            }

            self::$creditsExhausted = true;

            return true;
        }

        return false;
    }

    private function isTerminalFailureStatus(string $status): bool
    {
        return in_array($status, [
            'FAILED',
            'CANCELED',
            'CANCELLED',
            'ERROR',
            'CREDITS_INSUFFICIENT',
        ], true);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function parseContactResult(array $response, LeadEnrichmentInput $input): LeadEnrichmentResult
    {
        $row = Arr::get($response, 'data.0', []);
        if (! is_array($row)) {
            return new LeadEnrichmentResult;
        }

        $info = Arr::get($row, 'contact_info', []);
        if (! is_array($info)) {
            $info = [];
        }

        $email = trim((string) (Arr::get($info, 'most_probable_work_email.email') ?? ''));
        if ($email === '') {
            $email = trim((string) (Arr::get($info, 'most_probable_personal_email.email') ?? ''));
        }
        if ($email === '' && is_array(Arr::get($info, 'work_emails.0'))) {
            $email = trim((string) Arr::get($info, 'work_emails.0.email', ''));
        }

        $phone = trim((string) (Arr::get($info, 'most_probable_phone.number') ?? ''));
        if ($phone === '' && is_array(Arr::get($info, 'phones.0'))) {
            $phone = trim((string) Arr::get($info, 'phones.0.number', ''));
        }

        $profile = Arr::get($row, 'profile', []);
        $social = is_array($profile)
            ? app(LinkedInProfileSocialExtractor::class)->extractFromFullEnrichProfile($profile)
            : ['instagram_handle' => null, 'twitter_handle' => null, 'telegram_handle' => null];

        return new LeadEnrichmentResult(
            email: $email !== '' ? $email : null,
            phone: $phone !== '' ? $phone : null,
            instagramHandle: $social['instagram_handle'],
            twitterHandle: $social['twitter_handle'],
            telegramHandle: $social['telegram_handle'],
            emailLookupAttempted: $input->needsEmail(),
            phoneLookupAttempted: $input->needsPhone(),
            sources: ['fullenrich'],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{http_status: int, data: array<string, mixed>}
     */
    private function request(string $method, string $path, array $payload = []): array
    {
        $baseUrl = rtrim((string) config('services.fullenrich.base_url', 'https://app.fullenrich.com/api/v2'), '/');
        $apiKey = (string) config('services.fullenrich.api_key', '');

        $http = Http::withToken($apiKey)
            ->acceptJson()
            ->timeout((int) config('services.fullenrich.request_timeout_seconds', 30));

        $response = $method === 'get'
            ? $http->get($baseUrl.$path)
            : $http->post($baseUrl.$path, $payload);

        $json = $response->json();
        $data = is_array($json) ? $json : [];

        if (! $response->successful()) {
            $status = strtoupper((string) (Arr::get($data, 'status') ?? ''));

            if ($response->status() !== 402 && $status !== 'CREDITS_INSUFFICIENT') {
                Log::warning('[FullEnrich] ✗ '.$method.' '.$path.' → HTTP '.$response->status(), [
                    'status' => $status !== '' ? $status : null,
                ]);
            }

            return [
                'http_status' => $response->status(),
                'data' => $data,
            ];
        }

        return [
            'http_status' => $response->status(),
            'data' => $data,
        ];
    }
}

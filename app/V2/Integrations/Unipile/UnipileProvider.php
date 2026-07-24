<?php

namespace App\V2\Integrations\Unipile;

use App\Models\V2IntegrationAccount;
use App\V2\Contracts\Providers\AccountProviderInterface;
use App\V2\Contracts\Providers\InvitationProviderInterface;
use App\V2\Contracts\Providers\MessagingProviderInterface;
use App\V2\Contracts\Providers\PostProviderInterface;
use App\V2\Contracts\Providers\ProfileProviderInterface;
use App\V2\Contracts\Providers\SearchProviderInterface;
use App\V2\Contracts\Providers\WebhookProviderInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UnipileProvider implements AccountProviderInterface, SearchProviderInterface, InvitationProviderInterface, MessagingProviderInterface, ProfileProviderInterface, PostProviderInterface, WebhookProviderInterface
{
    private function isMock(): bool
    {
        return (bool) config('services.unipile.mock', false);
    }

    private function endpoint(string $key): string
    {
        $value = (string) config("services.unipile.endpoints.{$key}", '');
        if ($value === '') {
            throw new UnipileException("Missing Unipile endpoint config for key [{$key}].", 500, ['key' => $key]);
        }

        if (!str_starts_with($value, '/')) {
            $value = '/'.$value;
        }

        return $value;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.unipile.base_url'), '/');
    }

    /**
     * Hosted auth expects DSN root (without /api/v1 suffix).
     */
    private function hostedApiUrl(): string
    {
        $base = $this->baseUrl();
        return (string) preg_replace('#/api/v\d+/?$#', '', $base);
    }

    private function apiKey(): string
    {
        return (string) config('services.unipile.api_key', '');
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->asJson()
            ->connectTimeout(10)
            ->timeout(30)
            ->retry(3, 250)
            ->withHeaders([
                'X-API-KEY' => $this->apiKey(),
            ]);
    }

    private function request(string $method, string $endpoint, array $payload = []): array
    {
        $quiet = (bool) \Illuminate\Support\Arr::pull($payload, '_quiet', false);
        if ($this->isMock()) {
            Log::info('[Unipile] MOCK request', [
                'method' => strtoupper($method),
                'endpoint' => $endpoint,
                'payload' => $payload,
            ]);
            return [
                'mock' => true,
                'method' => strtoupper($method),
                'endpoint' => $endpoint,
                'payload' => $payload,
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if ($this->apiKey() === '') {
            throw new UnipileException('Unipile API key is missing.', 500);
        }

        $normalizedMethod = strtoupper($method);

        $options = in_array($normalizedMethod, ['GET', 'DELETE'], true)
            ? ['query' => $payload]
            : ['json' => $payload];

        // ── Outbound request log ──────────────────────────────────────────────
        $logPayload = $payload;
        if (isset($logPayload['access_token'])) {
            $logPayload['access_token'] = substr((string)$logPayload['access_token'], 0, 8).'…[redacted]';
        }
        Log::info('[Unipile] → '.$normalizedMethod.' '.$endpoint, [
            'payload' => $logPayload,
            'base_url' => $this->baseUrl(),
        ]);

        try {
            $response = $this->client()->send($normalizedMethod, $endpoint, $options)->throw();
        } catch (RequestException $exception) {
            $status = $exception->response?->status() ?? 502;
            $responseBody = $exception->response?->json();
            $responseText = $exception->response?->body() ?? $exception->getMessage();
            if ($quiet && $status === 422) {
                Log::info('[Unipile] Recipient not reachable (verify lookup)', [
                    'endpoint' => $endpoint,
                ]);
            } else {
                Log::error('[Unipile] ✗ '.$normalizedMethod.' '.$endpoint.' → HTTP '.$status, [
                    'payload'       => $logPayload,
                    'base_url'      => $this->baseUrl(),
                    'response_body' => $responseBody,
                    'response_text' => substr($responseText, 0, 500),
                ]);
            }
            // Build a human-readable error that includes the Unipile response detail
            $detail = '';
            if (is_array($responseBody)) {
                $detail = ': '.($responseBody['message'] ?? $responseBody['error'] ?? json_encode($responseBody));
            } elseif ($responseText) {
                $detail = ': '.substr($responseText, 0, 200);
            }
            throw new UnipileException(
                'Unipile API error (HTTP '.$status.')'.$detail,
                $status,
                [
                    'method'   => $normalizedMethod,
                    'endpoint' => $endpoint,
                    'payload'  => $payload,
                    'response' => $responseBody,
                    'hint'     => $this->errorHint(is_array($responseBody) ? $responseBody : [], $status),
                    'error_code' => is_array($responseBody) ? ($responseBody['type'] ?? null) : null,
                ]
            );
        }

        $responseData = $response->json() ?? [];
        Log::info('[Unipile] ✓ '.$normalizedMethod.' '.$endpoint.' → HTTP '.$response->status(), [
            'response_keys' => array_keys($responseData),
            'items_count' => count(Arr::get($responseData, 'items', Arr::get($responseData, 'data.items', []))),
        ]);

        return $responseData;
    }

    /**
     * Unipile POST /posts expects multipart/form-data (not JSON).
     *
     * @param  array<string, scalar|null>  $fields
     * @param  list<array{field?: string, path: string, filename?: string, mime?: string}>  $files
     */
    private function requestMultipart(string $method, string $endpoint, array $fields = [], array $files = []): array
    {
        if ($this->isMock()) {
            Log::info('[Unipile] MOCK multipart request', [
                'method' => strtoupper($method),
                'endpoint' => $endpoint,
                'fields' => $fields,
                'files' => array_map(fn (array $file) => $file['filename'] ?? basename($file['path']), $files),
            ]);

            return [
                'mock' => true,
                'method' => strtoupper($method),
                'endpoint' => $endpoint,
                'fields' => $fields,
                'files' => $files,
                'timestamp' => now()->toIso8601String(),
            ];
        }

        if ($this->apiKey() === '') {
            throw new UnipileException('Unipile API key is missing.', 500);
        }

        $normalizedMethod = strtoupper($method);
        $logFields = $fields;

        Log::info('[Unipile] → '.$normalizedMethod.' '.$endpoint.' (multipart)', [
            'fields' => $logFields,
            'files' => array_map(fn (array $file) => [
                'field' => $file['field'] ?? 'attachments',
                'filename' => $file['filename'] ?? basename($file['path']),
            ], $files),
            'base_url' => $this->baseUrl(),
        ]);

        try {
            $pending = Http::baseUrl($this->baseUrl())
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout(max(30, 60 * max(1, count($files))))
                ->retry(3, 250)
                ->withHeaders([
                    'X-API-KEY' => $this->apiKey(),
                ])
                ->asMultipart();

            foreach ($files as $file) {
                $pending = $pending->attach(
                    $file['field'] ?? 'attachments',
                    fopen($file['path'], 'r'),
                    $file['filename'] ?? basename($file['path']),
                    ['Content-Type' => $file['mime'] ?? 'application/octet-stream']
                );
            }

            $response = $pending->post($endpoint, $fields)->throw();
        } catch (RequestException $exception) {
            $status = $exception->response?->status() ?? 502;
            $responseBody = $exception->response?->json();
            $responseText = $exception->response?->body() ?? $exception->getMessage();

            Log::error('[Unipile] ✗ '.$normalizedMethod.' '.$endpoint.' (multipart) → HTTP '.$status, [
                'fields' => $logFields,
                'base_url' => $this->baseUrl(),
                'response_body' => $responseBody,
                'response_text' => substr($responseText, 0, 500),
            ]);

            $detail = '';
            if (is_array($responseBody)) {
                $detail = ': '.($responseBody['message'] ?? $responseBody['error'] ?? json_encode($responseBody));
            } elseif ($responseText) {
                $detail = ': '.substr($responseText, 0, 200);
            }

            throw new UnipileException(
                'Unipile API error (HTTP '.$status.')'.$detail,
                $status,
                [
                    'method' => $normalizedMethod,
                    'endpoint' => $endpoint,
                    'payload' => $fields,
                    'response' => $responseBody,
                    'hint' => $this->errorHint(is_array($responseBody) ? $responseBody : [], $status),
                    'error_code' => is_array($responseBody) ? ($responseBody['type'] ?? null) : null,
                ]
            );
        }

        $responseData = $response->json() ?? [];
        Log::info('[Unipile] ✓ '.$normalizedMethod.' '.$endpoint.' (multipart) → HTTP '.$response->status(), [
            'response_keys' => array_keys($responseData),
        ]);

        return $responseData;
    }

    /**
     * Scoped Unipile routes require account_id as a query/path param on this API — not in JSON body.
     */
    private function accountScopedRequest(string $method, string $endpoint, string $accountId, array $payload = []): array
    {
        $mode = (string) config('services.unipile.account_id_param', 'query');

        if ($mode === 'body') {
            $payload['account_id'] = $accountId;

            return $this->request($method, $endpoint, $payload);
        }

        if ($mode === 'path') {
            $endpoint = '/'.rawurlencode($accountId).ltrim($endpoint, '/');

            return $this->request($method, $endpoint, $payload);
        }

        $separator = str_contains($endpoint, '?') ? '&' : '?';

        return $this->request(
            $method,
            $endpoint.$separator.'account_id='.rawurlencode($accountId),
            $payload
        );
    }

    /**
     * @return list<string>
     */
    public static function validSearchUrlPatterns(): array
    {
        return [
            '#/search/results/people#i',
            '#/search/results/all#i',
            '#/search/results/companies#i',
            '#/search/results/content#i',
            '#/sales/search/people#i',
            '#/sales/search/company#i',
            '#/talent/search#i',
        ];
    }

    public static function isValidSearchUrl(string $url): bool
    {
        foreach (self::validSearchUrlPatterns() as $pattern) {
            if (preg_match($pattern, $url) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $responseBody
     */
    private function errorHint(array $responseBody, int $status): ?string
    {
        $type = (string) ($responseBody['type'] ?? '');

        if ($type === 'errors/no_client_session') {
            return 'Unipile rejected the request because no active tenant session exists for your API key. '
                .'Open the Unipile Dashboard → Settings → API, copy your exact DSN URL into UNIPILE_BASE_URL '
                .'(format: https://apiX.unipile.com:PORT/api/v1), generate a fresh Access Token for UNIPILE_API_KEY, '
                .'then run `php artisan config:clear`. For local UI testing without Unipile, set UNIPILE_MOCK=true.';
        }

        if ($type === 'errors/invalid_parameters' && str_contains((string) ($responseBody['detail'] ?? ''), 'account_id')) {
            return 'Unipile requires account_id as a query parameter for this route. '
                .'Set UNIPILE_ACCOUNT_ID_PARAM=query in .env (default) and retry.';
        }

        if ($status === 401 || $type === 'errors/missing_credentials') {
            return 'UNIPILE_API_KEY is missing or invalid. Generate a new Access Token in the Unipile Dashboard.';
        }

        return null;
    }

    /**
     * Quick connectivity probe for diagnostics (GET /accounts).
     *
     * @return array{ok: bool, status: int, type: string|null, message: string, hint: string|null}
     */
    public function probeConnectivity(): array
    {
        if ($this->isMock()) {
            return [
                'ok' => true,
                'status' => 200,
                'type' => null,
                'message' => 'UNIPILE_MOCK is enabled — live Unipile calls are bypassed.',
                'hint' => null,
            ];
        }

        if ($this->apiKey() === '') {
            return [
                'ok' => false,
                'status' => 500,
                'type' => 'config/missing_api_key',
                'message' => 'UNIPILE_API_KEY is not set.',
                'hint' => 'Add your Unipile Access Token to .env as UNIPILE_API_KEY.',
            ];
        }

        try {
            $response = $this->client()->get($this->endpoint('list_accounts'));
            $body = $response->json() ?? [];

            return [
                'ok' => $response->successful(),
                'status' => $response->status(),
                'type' => is_array($body) ? ($body['type'] ?? null) : null,
                'message' => $response->successful()
                    ? 'Unipile API is reachable.'
                    : 'Unipile API returned HTTP '.$response->status().'.',
                'hint' => $response->successful()
                    ? null
                    : $this->errorHint(is_array($body) ? $body : [], $response->status()),
            ];
        } catch (RequestException $exception) {
            $status = $exception->response?->status() ?? 502;
            $body = $exception->response?->json() ?? [];

            return [
                'ok' => false,
                'status' => $status,
                'type' => is_array($body) ? ($body['type'] ?? null) : null,
                'message' => 'Unipile API error (HTTP '.$status.').',
                'hint' => $this->errorHint(is_array($body) ? $body : [], $status),
            ];
        }
    }

    public function createHostedAuthLink(array $context = []): array
    {
        $successUrl = Arr::get($context, 'success_redirect_url', config('app.url').'/integrations?connected=1');
        $failUrl    = Arr::get($context, 'failure_redirect_url', config('app.url').'/integrations?error=1');
        $organizationId = Arr::get($context, 'organization_id');
        $notifyUrlBase  = Arr::get($context, 'notify_url', config('app.url').'/api/v2/provider-events/unipile');
        $notifyUrl = $notifyUrlBase;
        if (!empty($organizationId) && !str_contains($notifyUrlBase, 'organization_id=')) {
            $notifyUrl .= (str_contains($notifyUrlBase, '?') ? '&' : '?').'organization_id='.(int) $organizationId;
        }
        $provider = Arr::get($context, 'provider', 'LINKEDIN');
        $providers = $this->normalizeHostedProviders($provider);

        $payload = array_filter([
            'type'                  => 'create',
            'providers'             => $providers,
            'api_url'               => $this->hostedApiUrl(),
            'expiresOn'             => now()->utc()->addHours(2)->format('Y-m-d\TH:i:s.v\Z'),
            'success_redirect_url'  => $successUrl,
            'failure_redirect_url'  => $failUrl,
            'notify_url'            => $notifyUrl,
            'name'                  => Arr::get($context, 'name'),
            'state'                 => Arr::get($context, 'state'),
        ], fn ($value) => $value !== null);

        return $this->request('POST', $this->endpoint('hosted_auth_link'), $payload);
    }

    /**
     * Unipile hosted auth accepts either a wildcard string (*, *:MAILING) or a single-provider array.
     *
     * @return string|array<int, string>
     */
    private function normalizeHostedProviders(mixed $provider): string|array
    {
        if (is_array($provider)) {
            return count($provider) === 1 ? [$provider[0]] : '*:MAILING';
        }

        $value = strtoupper(trim((string) $provider));

        if ($value === '' || $value === '*') {
            return '*';
        }

        // Wildcards like *:MAILING must be sent as a string, not wrapped in an array.
        if (str_contains($value, ':')) {
            return $value;
        }

        return [$value];
    }

    /**
     * Connect a LinkedIn account using the li_at session cookie (Custom Auth method).
     * Unipile strongly recommends passing the user_agent from the same browser.
     */
    public function connectWithCookie(string $liAt, string $userAgent, array $options = []): array
    {
        $payload = array_filter([
            'provider'   => 'LINKEDIN',
            'access_token' => $liAt,
            'user_agent' => $userAgent,
            'country'    => Arr::get($options, 'country'),
            'proxy'      => Arr::get($options, 'proxy'),
        ], fn ($v) => $v !== null && $v !== '');

        return $this->request('POST', $this->endpoint('connect_account'), $payload);
    }

    /**
     * Connect a LinkedIn account using email + password credentials.
     */
    public function connectWithCredentials(string $email, string $password, array $options = []): array
    {
        $payload = array_filter([
            'provider' => 'LINKEDIN',
            'username' => $email,
            'password' => $password,
            'country'  => Arr::get($options, 'country'),
        ], fn ($v) => $v !== null && $v !== '');

        return $this->request('POST', $this->endpoint('connect_account'), $payload);
    }

    /**
     * Delete / disconnect a Unipile account.
     */
    public function disconnectAccount(string $accountId): array
    {
        return $this->request('DELETE', sprintf($this->endpoint('get_account'), $accountId));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function sendEmail(array $payload, array $context = []): array
    {
        $accountId = $this->resolveAccountId($payload, $context);
        $body = array_merge($payload, ['account_id' => $accountId]);

        return $this->request('POST', $this->endpoint('send_email'), $body);
    }

    public function listInvitations(): array
    {
        return $this->request('GET', $this->endpoint('list_invitations'));
    }

    public function acceptInvitation(string $invitationId): array
    {
        return $this->request('POST', $this->endpoint('accept_invitation').'/'.$invitationId.'/accept');
    }

    public function withdrawInvitation(string $invitationId): array
    {
        $endpoint = sprintf($this->endpoint('withdraw_invitation'), $invitationId);
        return $this->request('DELETE', $endpoint);
    }

    public function listAccounts(string $ownerId): array
    {
        return $this->request('GET', $this->endpoint('list_accounts'), [
            'owner_id' => $ownerId,
        ]);
    }

    public function getAccount(string $accountId): array
    {
        return $this->request('GET', $this->endpoint('list_accounts').'/'.$accountId);
    }

    public function reconnectAccount(string $accountId, array $context = []): array
    {
        return $this->request('POST', $this->endpoint('list_accounts').'/'.$accountId.'/reconnect', $context);
    }

    public function searchPeople(array $filters, array $context = []): array
    {
        $accountId = (string) Arr::pull($context, 'account_id');
        Arr::forget($context, ['owner_id', 'organization_id']);

        $apiFilters = [];

        if (! empty($filters['keywords'])) {
            $apiFilters['keywords'] = (string) $filters['keywords'];
        }
        if (! empty($filters['title'])) {
            $apiFilters['title'] = (string) $filters['title'];
        }
        if (! empty($filters['current_company'])) {
            $apiFilters['current_company'] = (string) $filters['current_company'];
        }
        // Unipile classic API uses plural field names for these filters.
        if (! empty($filters['past_company'])) {
            $apiFilters['past_companies'] = (string) $filters['past_company'];
        }
        if (! empty($filters['school'])) {
            $apiFilters['schools'] = (string) $filters['school'];
        }
        if (! empty($filters['network_depths'])) {
            $apiFilters['network_depths'] = $filters['network_depths'];
        }
        if (array_key_exists('open_link', $filters) && $filters['open_link'] !== null) {
            $apiFilters['open_link'] = (bool) $filters['open_link'];
        }

        // Always send count — Unipile defaults to ~10 when omitted.
        $apiFilters['count'] = max(1, min(100, (int) ($filters['limit'] ?? 10)));

        if (! empty($filters['location'])) {
            $locationIds = $this->normalizeLocationFilter($accountId, $filters['location']);
            if ($locationIds !== null) {
                $apiFilters['location'] = $locationIds;
            } else {
                // Location text cannot be sent directly — fold into keywords instead.
                $apiFilters['keywords'] = trim(($apiFilters['keywords'] ?? '').' '.(string) $filters['location']);
            }
        }

        if (trim((string) ($apiFilters['keywords'] ?? '')) === '' && count($apiFilters) <= 1) {
            throw new UnipileException(
                'Provide keywords or at least one supported filter for people search.',
                422
            );
        }

        $payload = array_merge([
            'api'      => 'classic',
            'category' => 'people',
        ], array_filter($apiFilters, fn ($v) => $v !== null && $v !== '' && $v !== []));

        return $this->accountScopedRequest('POST', $this->endpoint('search'), $accountId, $payload);
    }

    /**
     * @return list<string>|null
     */
    private function normalizeLocationFilter(string $accountId, mixed $location): ?array
    {
        if ($location === null || $location === '' || $location === []) {
            return null;
        }

        if (is_array($location)) {
            $ids = array_values(array_filter(
                array_map(static fn ($v) => (string) $v, $location),
                static fn (string $v) => preg_match('/^\d+$/', $v) === 1
            ));

            return $ids !== [] ? $ids : null;
        }

        $text = trim((string) $location);
        if ($text === '') {
            return null;
        }

        if (preg_match('/^\d+$/', $text) === 1) {
            return [$text];
        }

        $ids = $this->resolveClassicSearchParameterIds($accountId, 'LOCATION', $text);

        return $ids !== [] ? $ids : null;
    }

    /**
     * @return list<string>
     */
    private function resolveClassicSearchParameterIds(string $accountId, string $type, string $keywords, int $limit = 3): array
    {
        if (trim($keywords) === '') {
            return [];
        }

        try {
            $result = $this->request('GET', $this->endpoint('search').'/parameters', [
                'account_id' => $accountId,
                'type' => $type,
                'keywords' => $keywords,
            ]);
        } catch (\Throwable) {
            return [];
        }

        $items = Arr::get($result, 'items', []);
        if (! is_array($items)) {
            return [];
        }

        $ids = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = $item['id'] ?? null;
            if ($id === null || $id === '') {
                continue;
            }

            $ids[] = (string) $id;

            if (count($ids) >= $limit) {
                break;
            }
        }

        return $ids;
    }

    /**
     * Run a search from a full LinkedIn search URL.
     * Unipile will infer all filters from the URL directly.
     * Supports: linkedin.com/search/results/people?...
     *           linkedin.com/sales/search/people?...  (Sales Nav)
     *           linkedin.com/talent/search?...         (Recruiter)
     */
    public function searchFromUrl(string $url, string $accountId, int $limit = 20): array
    {
        if (! self::isValidSearchUrl($url)) {
            throw new UnipileException(
                'LinkedIn URL is not a supported search page. Use a people search URL such as '
                .'https://www.linkedin.com/search/results/people?… or a Sales Navigator search URL.',
                422,
                ['url' => $url]
            );
        }

        $payload = array_filter([
            'url'   => $url,
            'count' => $limit,
        ]);

        return $this->accountScopedRequest('POST', $this->endpoint('search'), $accountId, $payload);
    }

    /**
     * Fetch a single LinkedIn profile by its public URL or identifier.
     */
    public function getProfileByUrl(string $url, string $accountId): array
    {
        if (! preg_match('~linkedin\.com/in/([^/?#]+)~i', $url, $matches)) {
            throw new UnipileException('Invalid LinkedIn profile URL. Expected linkedin.com/in/username.', 422, ['url' => $url]);
        }

        $identifier = $matches[1];
        $profile = $this->request('GET', '/users/'.$identifier, ['account_id' => $accountId]);

        return $this->normalizeProfileItem($profile, $identifier);
    }

    /**
     * Map Unipile profile response to the lead shape used by search + CRM persist.
     *
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private function normalizeProfileItem(array $profile, ?string $fallbackIdentifier = null): array
    {
        $providerId = (string) (
            Arr::get($profile, 'provider_id')
            ?? Arr::get($profile, 'id')
            ?? ''
        );
        $publicId = (string) (
            Arr::get($profile, 'public_identifier')
            ?? $fallbackIdentifier
            ?? ''
        );

        if ($providerId === '' && $publicId !== '') {
            $providerId = $publicId;
        }

        $name = trim((string) (
            Arr::get($profile, 'full_name')
            ?? Arr::get($profile, 'name')
            ?? trim((Arr::get($profile, 'first_name', '').' '.Arr::get($profile, 'last_name', '')))
        ));

        $email = $this->extractProfileEmail($profile);

        return array_merge($profile, array_filter([
            'id' => $providerId !== '' ? $providerId : null,
            'provider_id' => $providerId !== '' ? $providerId : null,
            'public_identifier' => $publicId !== '' ? $publicId : null,
            'full_name' => $name !== '' ? $name : null,
            'profile_url' => $publicId !== '' ? 'https://www.linkedin.com/in/'.$publicId : null,
            'email' => $email,
        ], fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * LinkedIn emails are not included in search results — only on full profile lookups.
     *
     * @param  array<string, mixed>  $profile
     */
    public function extractProfileEmail(array $profile): ?string
    {
        $direct = trim((string) (Arr::get($profile, 'email') ?? ''));
        if ($direct !== '' && filter_var($direct, FILTER_VALIDATE_EMAIL)) {
            return $direct;
        }

        $contactInfo = Arr::get($profile, 'contact_info', Arr::get($profile, 'contactInfo', []));
        if (! is_array($contactInfo)) {
            return null;
        }

        foreach (Arr::get($contactInfo, 'emails', []) as $candidate) {
            if (is_array($candidate)) {
                $candidate = Arr::get($candidate, 'email')
                    ?? Arr::get($candidate, 'address')
                    ?? Arr::get($candidate, 'value')
                    ?? '';
            }
            $email = trim((string) $candidate);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        }

        $single = trim((string) (Arr::get($contactInfo, 'email') ?? ''));
        if ($single !== '' && filter_var($single, FILTER_VALIDATE_EMAIL)) {
            return $single;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    public function extractProfilePhone(array $profile): ?string
    {
        $direct = trim((string) (Arr::get($profile, 'phone') ?? ''));
        if ($direct !== '') {
            return $this->normalizePhone($direct);
        }

        $contactInfo = Arr::get($profile, 'contact_info', Arr::get($profile, 'contactInfo', []));
        if (! is_array($contactInfo)) {
            return null;
        }

        foreach (Arr::get($contactInfo, 'phones', Arr::get($contactInfo, 'phone_numbers', [])) as $candidate) {
            if (is_array($candidate)) {
                $candidate = Arr::get($candidate, 'number')
                    ?? Arr::get($candidate, 'phone')
                    ?? Arr::get($candidate, 'value')
                    ?? '';
            }
            $phone = $this->normalizePhone((string) $candidate);
            if ($phone !== '') {
                return $phone;
            }
        }

        $single = $this->normalizePhone((string) (Arr::get($contactInfo, 'phone') ?? ''));
        if ($single !== '') {
            return $single;
        }

        return null;
    }

    public function normalizePhone(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', trim($raw)) ?? '';

        return strlen($digits) >= 8 ? $digits : '';
    }

    /**
     * Lookup a user on a messaging provider (WhatsApp, Instagram, etc.).
     *
     * @return array<string, mixed>
     */
    public function lookupMessagingUser(string $identifier, string $accountId, bool $quiet = false): array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            throw new UnipileException('Identifier is required for messaging user lookup.');
        }

        return $this->getProfileByIdentifier($identifier, [
            'account_id' => $accountId,
            '_quiet' => $quiet,
        ]);
    }

    /**
     * Extract Unipile provider id from a user profile response.
     */
    public function extractProviderId(array $profile): ?string
    {
        foreach (['provider_id', 'provider_messaging_id', 'id'] as $key) {
            $value = trim((string) (Arr::get($profile, $key) ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    public function searchCompanies(array $filters, array $context = []): array
    {
        $accountId = Arr::pull($context, 'account_id');
        Arr::forget($context, ['owner_id', 'organization_id']);

        $payload = array_filter(array_merge([
            'api'      => 'classic',
            'category' => 'companies',
        ], $filters), fn ($v) => $v !== null && $v !== '' && $v !== []);

        if (! $accountId) {
            $accountId = $this->resolveAccountId([], $context);
        }

        return $this->accountScopedRequest('POST', $this->endpoint('search'), (string) $accountId, $payload);
    }

    /**
     * LinkedIn content search — used for competitor company posts (category=posts).
     *
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $context
     */
    public function searchPosts(array $filters, array $context = []): array
    {
        $accountId = Arr::pull($context, 'account_id');
        Arr::forget($context, ['owner_id', 'organization_id']);

        $payload = array_filter(array_merge([
            'api'      => 'classic',
            'category' => 'posts',
        ], $filters), fn ($v) => $v !== null && $v !== '' && $v !== []);

        if (! $accountId) {
            $accountId = $this->resolveAccountId([], $context);
        }

        return $this->accountScopedRequest('POST', $this->endpoint('search'), (string) $accountId, $payload);
    }

    public function sendInvitation(array $payload, array $context = []): array
    {
        $accountId = $this->resolveAccountId($payload, $context);
        $providerId = (string) (
            Arr::get($payload, 'provider_id')
            ?? Arr::get($payload, 'recipient_id')
            ?? ''
        );

        if ($providerId === '') {
            throw new UnipileException('provider_id or recipient_id is required for invitations.');
        }

        return $this->request('POST', $this->endpoint('send_invitation'), array_filter([
            'account_id' => $accountId,
            'provider_id' => $providerId,
            'message' => Arr::get($payload, 'message'),
        ], fn ($value) => $value !== null && $value !== ''));
    }

    public function listSentInvitations(array $filters = [], array $context = []): array
    {
        return $this->request('GET', $this->endpoint('send_invitation').'/sent', array_merge($filters, $context));
    }

    public function listReceivedInvitations(array $filters = [], array $context = []): array
    {
        return $this->request('GET', $this->endpoint('send_invitation').'/received', array_merge($filters, $context));
    }

    public function handleReceivedInvitation(string $invitationId, string $action, array $context = []): array
    {
        $accountId = (string) ($context['account_id'] ?? '');
        $sharedSecret = (string) ($context['shared_secret'] ?? '');

        if ($accountId === '') {
            throw new UnipileException('account_id is required.');
        }

        if ($sharedSecret === '') {
            throw new UnipileException(
                'shared_secret is required to handle LinkedIn invitations. Reload invitations and try again.',
                422
            );
        }

        $normalizedAction = match ($action) {
            'reject', 'decline' => 'decline',
            default => 'accept',
        };

        return $this->request('POST', $this->endpoint('send_invitation').'/received/'.$invitationId, [
            'provider' => 'LINKEDIN',
            'shared_secret' => $sharedSecret,
            'account_id' => $accountId,
            'action' => $normalizedAction,
        ]);
    }

    public function cancelInvitation(string $invitationId, array $context = []): array
    {
        $accountId = (string) ($context['account_id'] ?? '');
        if ($accountId === '') {
            throw new UnipileException('account_id is required.');
        }

        return $this->request('DELETE', $this->endpoint('send_invitation').'/sent/'.$invitationId, [
            'account_id' => $accountId,
        ]);
    }

    public function listChats(array $filters = [], array $context = []): array
    {
        return $this->request('GET', $this->endpoint('start_chat'), array_merge($filters, $context));
    }

    public function listMessages(string $chatId, array $filters = [], array $context = []): array
    {
        return $this->request('GET', $this->endpoint('start_chat').'/'.$chatId.'/messages', array_merge($filters, $context));
    }

    public function sendMessage(string $chatId, array $payload, array $context = []): array
    {
        $endpoint = sprintf($this->endpoint('send_message'), $chatId);

        $files = (array) Arr::pull($payload, '_files', []);
        if ($files !== []) {
            return $this->sendMessageMultipart($endpoint, $payload, $files);
        }

        return $this->request('POST', $endpoint, array_merge($payload, $context));
    }

    /**
     * @param  array<string, scalar|null>  $fields
     * @param  list<array{path: string, filename?: string, mime?: string}>  $files
     */
    public function sendMessageMultipart(string $endpoint, array $fields = [], array $files = []): array
    {
        $fields = array_filter($fields, fn ($v) => $v !== null && $v !== '');

        if ($this->isMock()) {
            return [
                'mock' => true,
                'endpoint' => $endpoint,
                'fields' => $fields,
                'files' => array_map(fn (array $f) => $f['filename'] ?? basename($f['path']), $files),
            ];
        }

        if ($this->apiKey() === '') {
            throw new UnipileException('Unipile API key is missing.', 500);
        }

        Log::info('[Unipile] → POST '.$endpoint.' (multipart message)', [
            'fields' => array_keys($fields),
            'files' => array_map(fn (array $f) => $f['filename'] ?? basename($f['path']), $files),
        ]);

        try {
            $pending = Http::baseUrl($this->baseUrl())
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout(max(60, 30 * max(1, count($files))))
                ->retry(2, 250)
                ->withHeaders(['X-API-KEY' => $this->apiKey()])
                ->asMultipart();

            foreach ($files as $file) {
                $pending = $pending->attach(
                    'attachments',
                    fopen($file['path'], 'r'),
                    $file['filename'] ?? basename($file['path']),
                    ['Content-Type' => $file['mime'] ?? 'application/octet-stream']
                );
            }

            $response = $pending->post($endpoint, $fields)->throw();
        } catch (RequestException $exception) {
            $status = $exception->response?->status() ?? 502;
            throw new UnipileException(
                'Unipile API error (HTTP '.$status.'): '.substr($exception->response?->body() ?? $exception->getMessage(), 0, 300),
                $status
            );
        }

        return $response->json() ?? [];
    }

    /**
     * Stream attachment bytes from Unipile — nothing is stored locally.
     */
    public function downloadMessageAttachment(string $messageId, string $attachmentId, array $context = []): \Illuminate\Http\Client\Response
    {
        if ($this->apiKey() === '') {
            throw new UnipileException('Unipile API key is missing.', 500);
        }

        $endpoint = '/messages/'.rawurlencode($messageId).'/attachments/'.rawurlencode($attachmentId);
        $query = [];
        if (! empty($context['account_id'])) {
            $query['account_id'] = $context['account_id'];
        }

        return Http::baseUrl($this->baseUrl())
            ->withHeaders(['X-API-KEY' => $this->apiKey()])
            ->timeout(120)
            ->get($endpoint, $query);
    }

    public function startChat(array $payload, array $context = []): array
    {
        $accountId = $this->resolveAccountId($payload, $context);
        $attendeeIds = Arr::get($payload, 'attendees_ids')
            ?? Arr::get($payload, 'attendee_ids')
            ?? [];

        if (! is_array($attendeeIds)) {
            $attendeeIds = [$attendeeIds];
        }

        $attendeeIds = array_values(array_filter(array_map('strval', $attendeeIds)));
        if ($attendeeIds === []) {
            $fallback = (string) (Arr::get($payload, 'provider_id') ?? Arr::get($payload, 'recipient_id') ?? '');
            if ($fallback !== '') {
                $attendeeIds = [$fallback];
            }
        }

        if ($attendeeIds === []) {
            throw new UnipileException('attendee_ids is required to start a chat.');
        }

        return $this->request('POST', $this->endpoint('start_chat'), array_filter([
            'account_id' => $accountId,
            'attendees_ids' => $attendeeIds,
            'text' => Arr::get($payload, 'text'),
        ], fn ($value) => $value !== null && $value !== '' && $value !== []));
    }

    public function markChatReadState(string $chatId, bool $isRead, array $context = []): array
    {
        return $this->request('PATCH', $this->endpoint('start_chat').'/'.$chatId, array_merge([
            'read' => $isRead,
        ], $context));
    }

    public function getProfileByIdentifier(string $identifier, array $context = []): array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            throw new UnipileException('Profile identifier is required.');
        }

        return $this->request('GET', '/users/'.rawurlencode($identifier), $context);
    }

    public function listRelations(array $filters = [], array $context = []): array
    {
        return $this->request('GET', '/users/relations', array_merge($filters, $context));
    }

    public function listFollowers(array $filters = [], array $context = []): array
    {
        return $this->request('GET', '/users/followers', array_merge($filters, $context));
    }

    /**
     * Publish a post to LinkedIn via Unipile.
     * Docs: POST /posts (multipart/form-data)
     *
     * @param  array{
     *     attachments?: list<array{path: string, filename?: string, mime?: string, field?: string}>,
     *     video_thumbnail?: array{path: string, filename?: string, mime?: string},
     *     repost?: string,
     *     external_link?: string,
     *     as_organization?: string,
     * }  $options
     */
    public function createPost(string $accountId, string $content, array $options = []): array
    {
        $fields = array_filter([
            'account_id' => $accountId,
            'text' => $content,
            'repost' => Arr::get($options, 'repost'),
            'external_link' => Arr::get($options, 'external_link'),
            'as_organization' => Arr::get($options, 'as_organization'),
        ], fn ($v) => $v !== null && $v !== '');

        $files = [];
        foreach ((array) Arr::get($options, 'attachments', []) as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }

            $path = (string) ($attachment['path'] ?? '');
            if ($path === '' || !is_readable($path)) {
                continue;
            }

            $files[] = [
                'field' => (string) ($attachment['field'] ?? 'attachments'),
                'path' => $path,
                'filename' => (string) ($attachment['filename'] ?? basename($path)),
                'mime' => (string) ($attachment['mime'] ?? (mime_content_type($path) ?: 'application/octet-stream')),
            ];
        }

        $thumbnail = Arr::get($options, 'video_thumbnail');
        if (is_array($thumbnail)) {
            $thumbPath = (string) ($thumbnail['path'] ?? '');
            if ($thumbPath !== '' && is_readable($thumbPath)) {
                $files[] = [
                    'field' => 'video_thumbnail',
                    'path' => $thumbPath,
                    'filename' => (string) ($thumbnail['filename'] ?? basename($thumbPath)),
                    'mime' => (string) ($thumbnail['mime'] ?? (mime_content_type($thumbPath) ?: 'image/jpeg')),
                ];
            }
        }

        return $this->requestMultipart('POST', '/posts', $fields, $files);
    }

    public function listPosts(array $filters = [], array $context = []): array
    {
        return $this->request('GET', '/posts', array_merge($filters, $context));
    }

    public function listUserPosts(string $identifier, array $filters = [], array $context = []): array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            throw new UnipileException('Company or user identifier is required to list posts.');
        }

        return $this->request(
            'GET',
            '/users/'.rawurlencode($identifier).'/posts',
            array_merge($filters, $context)
        );
    }

    public function listPostComments(string $postId, array $filters = [], array $context = []): array
    {
        return $this->request('GET', '/posts/'.$this->encodePostId($postId).'/comments', array_merge($filters, $context));
    }

    public function listPostReactions(string $postId, array $filters = [], array $context = []): array
    {
        return $this->request('GET', '/posts/'.$this->encodePostId($postId).'/reactions', array_merge($filters, $context));
    }

    private function encodePostId(string $postId): string
    {
        return rawurlencode(trim($postId));
    }

    public function performLinkedinProfileAction(string $action, array $payload = []): array
    {
        $accountId = $this->resolveAccountId($payload, []);
        $providerId = (string) (
            Arr::get($payload, 'provider_id')
            ?? Arr::get($payload, 'profile_id')
            ?? Arr::get($payload, 'recipient_id')
            ?? ''
        );

        if ($providerId === '') {
            throw new UnipileException('profile_id is required for LinkedIn profile actions.');
        }

        if ($action === 'view_profile') {
            return $this->getProfileByIdentifier($providerId, ['account_id' => $accountId]);
        }

        if ($action === 'endorse') {
            $profile = $this->getProfileByIdentifier($providerId, [
                'account_id' => $accountId,
                'linkedin_sections' => ['skills'],
            ]);
            $skillId = $this->firstEndorseableSkillId($profile);
            if ($skillId === null) {
                throw new UnipileException('No endorseable skill found on this profile.');
            }

            return $this->request('POST', '/linkedin/profile/endorse', [
                'account_id' => $accountId,
                'profile_id' => $providerId,
                'skill_endorsement_id' => $skillId,
            ]);
        }

        if ($action === 'follow') {
            $profile = $this->getProfileByIdentifier($providerId, ['account_id' => $accountId]);

            return [
                'action' => 'follow',
                'profile_viewed' => true,
                'note' => 'LinkedIn follow is not exposed by Unipile; profile was retrieved instead.',
                'profile' => $profile,
            ];
        }

        if ($action === 'unfollow') {
            throw new UnipileException('LinkedIn unfollow is not supported via Unipile.');
        }

        return $this->request('POST', '/linkedin/user/'.$providerId, array_filter([
            'account_id' => $accountId,
            'action' => $action,
        ], fn ($value) => $value !== null && $value !== ''));
    }

    /**
     * Resolve a LinkedIn identifier to the Unipile provider_id (ACo… format).
     *
     * @return array{provider_id: string|null, profile: array<string, mixed>}
     */
    public function resolveProviderId(string $identifier, array $context = []): array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return ['provider_id' => null, 'profile' => []];
        }

        if (preg_match('/^(ACo|ADo|ACw|AE)/i', $identifier)) {
            return ['provider_id' => $identifier, 'profile' => []];
        }

        $profile = $this->getProfileByIdentifier($identifier, $context);
        $providerId = (string) (
            Arr::get($profile, 'provider_id')
            ?? Arr::get($profile, 'id')
            ?? Arr::get($profile, 'public_identifier')
            ?? ''
        );

        return [
            'provider_id' => $providerId !== '' ? $providerId : null,
            'profile' => is_array($profile) ? $profile : [],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $context
     */
    private function resolveAccountId(array $payload, array $context): string
    {
        $accountId = (string) (
            Arr::get($payload, 'account_id')
            ?? Arr::get($payload, '_unipile_account_id')
            ?? Arr::get($context, 'account_id')
            ?? ''
        );

        if ($accountId !== '') {
            return $accountId;
        }

        $ownerId = (int) (Arr::get($context, 'owner_id') ?? Arr::get($payload, 'owner_id') ?? 0);
        if ($ownerId > 0) {
            $resolved = V2IntegrationAccount::activeUnipileAccountId($ownerId);
            if ($resolved) {
                return $resolved;
            }
        }

        throw new UnipileException('No connected Unipile account_id available for this request.');
    }

    /**
     * @param array<string, mixed> $profile
     */
    private function firstEndorseableSkillId(array $profile): ?int
    {
        $skills = Arr::get($profile, 'skills', Arr::get($profile, 'skills_preview', []));
        if (! is_array($skills)) {
            return null;
        }

        foreach ($skills as $skill) {
            if (! is_array($skill)) {
                continue;
            }

            $endorsementId = Arr::get($skill, 'endorsement_id');
            if ($endorsementId !== null && $endorsementId !== '') {
                return (int) $endorsementId;
            }
        }

        return null;
    }

    public function reactToPost(string $postId, string $reaction, array $context = []): array
    {
        return $this->request('POST', '/posts/'.$postId.'/reactions', array_merge([
            'reaction' => $reaction,
        ], $context));
    }

    public function verifySignature(array $headers, string $rawBody): bool
    {
        $secret = (string) config('services.unipile.webhook_secret', '');
        if ($secret === '') {
            return true;
        }

        $signatureHeader = (string) (
            Arr::get($headers, 'unipile-signature')
            ?? Arr::get($headers, 'Unipile-Signature')
            ?? Arr::get($headers, 'x-unipile-signature')
            ?? Arr::get($headers, 'X-Unipile-Signature')
            ?? ''
        );

        if ($signatureHeader === '') {
            return false;
        }

        if (str_contains($signatureHeader, 'v0=')) {
            $parts = [];
            foreach (explode(',', $signatureHeader) as $part) {
                $segments = explode('=', $part, 2);
                if (count($segments) === 2) {
                    $parts[trim($segments[0])] = trim($segments[1]);
                }
            }

            $timestamp = (string) ($parts['t'] ?? '');
            $received = (string) ($parts['v0'] ?? '');
            if ($timestamp === '' || $received === '') {
                return false;
            }

            $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

            return hash_equals($expected, $received);
        }

        $computed = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($computed, $signatureHeader);
    }

    public function parseEvent(array $payload): array
    {
        $inner = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];

        $text = (string) (
            Arr::get($inner, 'text')
            ?? Arr::get($inner, 'body')
            ?? Arr::get($inner, 'message')
            ?? Arr::get($payload, 'text')
            ?? Arr::get($payload, 'body')
            ?? Arr::get($payload, 'message')
            ?? Arr::get($payload, 'data.text')
            ?? Arr::get($payload, 'data.message')
            ?? ''
        );
        $chatId = (string) (
            Arr::get($inner, 'chat_id')
            ?? Arr::get($payload, 'chat_id')
            ?? Arr::get($payload, 'data.chat_id')
            ?? ''
        );
        $messageId = (string) (
            Arr::get($inner, 'id')
            ?? Arr::get($inner, 'message_id')
            ?? Arr::get($payload, 'message_id')
            ?? Arr::get($payload, 'data.message_id')
            ?? ''
        );
        $accountId = (string) (
            Arr::get($payload, 'account_id')
            ?? Arr::get($inner, 'account_id')
            ?? Arr::get($payload, 'data.account_id')
            ?? ''
        );

        return array_merge($payload, [
            'account_id' => $accountId !== '' ? $accountId : ($payload['account_id'] ?? null),
            'chat_id' => $chatId !== '' ? $chatId : ($payload['chat_id'] ?? null),
            'text' => $text !== '' ? $text : ($payload['text'] ?? null),
            'message_id' => $messageId !== '' ? $messageId : ($payload['message_id'] ?? null),
            'data' => array_merge(
                is_array($payload['data'] ?? null) ? $payload['data'] : [],
                array_filter([
                    'chat_id' => $chatId !== '' ? $chatId : null,
                    'text' => $text !== '' ? $text : null,
                    'message_id' => $messageId !== '' ? $messageId : null,
                    'account_id' => $accountId !== '' ? $accountId : null,
                ], fn ($v) => $v !== null && $v !== '')
            ),
        ]);
    }

    public function eventType(array $payload): string
    {
        $raw = (string) (Arr::get($payload, 'type') ?? Arr::get($payload, 'event', 'unknown'));

        return match ($raw) {
            'message.new' => $this->messageNewDirection($payload),
            'message_received' => $this->messageNewDirection($payload),
            'new_message' => $this->messageNewDirection($payload),
            'message_read' => 'chat.read',
            'message_reaction' => 'message.reaction',
            'message_delivered' => 'message.delivered',
            'message_edited' => 'message.sent',
            'message_deleted' => 'message.delete',
            'message.receipt.read' => 'chat.read',
            'message.update' => 'message.sent',
            default => str_replace('_', '.', $raw),
        };
    }

    private function messageNewDirection(array $payload): string
    {
        if ($this->isFromAccountOwner($payload)) {
            return 'message.sent';
        }

        return 'message.received';
    }

    /**
     * Unipile often emits message_received even for messages the connected account sent.
     *
     * @param  array<string, mixed>  $payload
     */
    public function isFromAccountOwner(array $payload): bool
    {
        $inner = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];
        $fromMe = Arr::get($inner, 'is_from_me')
            ?? Arr::get($inner, 'from_me')
            ?? Arr::get($inner, 'is_sender')
            ?? Arr::get($payload, 'is_from_me')
            ?? Arr::get($payload, 'from_me')
            ?? Arr::get($payload, 'is_sender');

        if ($fromMe === true || $fromMe === 1 || $fromMe === 'true' || $fromMe === '1') {
            return true;
        }

        $networkDistance = (string) (
            Arr::get($payload, 'sender.attendee_specifics.network_distance')
            ?? Arr::get($inner, 'sender.attendee_specifics.network_distance')
            ?? ''
        );

        if (strtoupper($networkDistance) === 'SELF') {
            return true;
        }

        $accountUserId = (string) (
            Arr::get($payload, 'account_info.user_id')
            ?? Arr::get($inner, 'account_info.user_id')
            ?? ''
        );
        $senderProviderId = (string) (
            Arr::get($payload, 'sender.attendee_provider_id')
            ?? Arr::get($inner, 'sender.attendee_provider_id')
            ?? ''
        );

        return $accountUserId !== '' && $senderProviderId !== '' && $accountUserId === $senderProviderId;
    }

    public function eventId(array $payload): string
    {
        return (string) Arr::get($payload, 'id', Arr::get($payload, 'event_id', uniqid('event_', true)));
    }
}

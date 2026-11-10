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
use Illuminate\Support\Facades\Cache;
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

    private function apiKey(): string
    {
        return (string) config('services.unipile.api_key', '');
    }

    /**
     * Prefix an account-scoped path: /{account_id}/chats/...
     */
    private function accountPath(string $accountId, string $endpoint): string
    {
        $accountId = trim($accountId);
        if ($accountId === '') {
            throw new UnipileException('account_id is required for this Unipile v2 route.', 422);
        }

        $endpoint = '/'.ltrim($endpoint, '/');

        return '/'.rawurlencode($accountId).$endpoint;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withoutAccountId(array $payload): array
    {
        Arr::forget($payload, ['account_id', '_unipile_account_id']);

        return $payload;
    }

    /**
     * Encode a local file as a Unipile v2 attachment object (JSON + base64).
     *
     * @param  array{path: string, filename?: string, mime?: string, content_type?: string}  $file
     * @return array{filename: string, content_type: string, data: string}|null
     */
    private function encodeAttachmentFile(array $file, string $dataKey = 'data'): ?array
    {
        $path = (string) ($file['path'] ?? '');
        if ($path === '' || ! is_readable($path)) {
            return null;
        }

        $binary = file_get_contents($path);
        if ($binary === false) {
            return null;
        }

        $contentType = (string) (
            $file['content_type']
            ?? $file['mime']
            ?? (mime_content_type($path) ?: 'application/octet-stream')
        );

        return [
            'filename' => (string) ($file['filename'] ?? basename($path)),
            'content_type' => $contentType,
            $dataKey => base64_encode($binary),
        ];
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
            Log::debug('[Unipile] MOCK request', [
                'method' => strtoupper($method),
                'endpoint' => $endpoint,
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
            throw new UnipileException('Messaging API key is missing.', 500);
        }

        $accountId = trim((string) ($payload['account_id'] ?? ''));
        Arr::forget($payload, ['_unipile_account_id']);

        // Path-scoped routes already include /{account_id}/... — do not also send it as query/body.
        // Auth routes (/auth/link, /auth/intent) still need account_id in the JSON body for reconnect.
        if ($accountId !== '' && str_starts_with($endpoint, '/'.rawurlencode($accountId).'/')) {
            Arr::forget($payload, ['account_id']);
        }

        if ($accountId === '') {
            return $this->sendRequest($method, $endpoint, $payload, $quiet);
        }

        // One LinkedIn/Unipile account at a time across enrich, harvest, outreach, etc.
        $lockSeconds = max(5, (int) config('services.unipile_pacing.account_lock_seconds', 25));
        $waitSeconds = max(1, (int) config('services.unipile_pacing.account_lock_wait_seconds', 20));
        $lock = Cache::lock('unipile:account:'.$accountId, $lockSeconds);

        if (! $lock->block($waitSeconds)) {
            throw new UnipileException(
                'This LinkedIn account is busy with another action. Please retry in a moment.',
                429,
                ['account_id' => $accountId, 'endpoint' => $endpoint]
            );
        }

        try {
            return $this->sendRequest($method, $endpoint, $payload, $quiet);
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sendRequest(string $method, string $endpoint, array $payload, bool $quiet = false): array
    {
        $normalizedMethod = strtoupper($method);

        $options = in_array($normalizedMethod, ['GET', 'DELETE'], true)
            ? ['query' => $payload]
            : ['json' => $payload];

        // ── Outbound request log ──────────────────────────────────────────────
        $logPayload = $payload;
        if (isset($logPayload['access_token'])) {
            $logPayload['access_token'] = substr((string)$logPayload['access_token'], 0, 8).'…[redacted]';
        }
        Log::debug('[Unipile] → '.$normalizedMethod.' '.$endpoint, [
            'payload' => $logPayload,
        ]);

        try {
            $response = $this->client()->send($normalizedMethod, $endpoint, $options)->throw();
        } catch (RequestException $exception) {
            $status = $exception->response?->status() ?? 502;
            $responseBody = $exception->response?->json();
            $responseText = $exception->response?->body() ?? $exception->getMessage();
            if ($quiet && in_array($status, [404, 422], true)) {
                Log::debug('[Unipile] Recipient not reachable (verify lookup)', [
                    'endpoint' => $endpoint,
                    'status' => $status,
                ]);
            } else {
                Log::error('[Unipile] ✗ '.$normalizedMethod.' '.$endpoint.' → HTTP '.$status, [
                    'payload'       => $logPayload,
                    'base_url'      => $this->baseUrl(),
                    'response_body' => $responseBody,
                    'response_text' => substr($responseText, 0, 500),
                ]);
            }
            // Build a human-readable error — never embed full API schema dumps in the message
            $detail = '';
            if (is_array($responseBody)) {
                $title = trim((string) ($responseBody['title'] ?? ''));
                $bodyDetail = trim((string) ($responseBody['detail'] ?? $responseBody['message'] ?? $responseBody['error'] ?? ''));
                if ($bodyDetail !== '' && (str_contains($bodyDetail, '"schema"') || strlen($bodyDetail) > 200)) {
                    $bodyDetail = $title !== '' ? $title : 'Invalid request parameters';
                }
                if ($bodyDetail !== '') {
                    $detail = ': '.$bodyDetail;
                } elseif ($title !== '') {
                    $detail = ': '.$title;
                } elseif (isset($responseBody['type'])) {
                    $detail = ': '.(string) $responseBody['type'];
                }
            } elseif ($responseText) {
                // Never dump nginx HTML error pages into activity logs.
                $detail = $this->sanitizeHttpErrorDetail($responseText, $status);
            }
            throw new UnipileException(
                'Messaging error (HTTP '.$status.')'.$detail,
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
        Log::debug('[Unipile] ✓ '.$normalizedMethod.' '.$endpoint.' → HTTP '.$response->status());

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
            Log::debug('[Unipile] MOCK multipart request', [
                'method' => strtoupper($method),
                'endpoint' => $endpoint,
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
            throw new UnipileException('Messaging API key is missing.', 500);
        }

        $normalizedMethod = strtoupper($method);
        $logFields = $fields;

        Log::debug('[Unipile] → '.$normalizedMethod.' '.$endpoint.' (multipart)', [
            'fields' => array_keys($logFields),
            'files' => count($files),
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
                $detail = ': '.($responseBody['message'] ?? $responseBody['error'] ?? 'Invalid request');
            } elseif ($responseText) {
                $detail = $this->sanitizeHttpErrorDetail($responseText, $status);
            }

            throw new UnipileException(
                'Messaging error (HTTP '.$status.')'.$detail,
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
        Log::debug('[Unipile] ✓ '.$normalizedMethod.' '.$endpoint.' (multipart) → HTTP '.$response->status());

        return $responseData;
    }

    /**
     * Unipile v2: every account-scoped route is /{account_id}/...
     * account_id must not appear in query or JSON body.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, scalar|null>  $extraQuery  GET/DELETE query params only
     */
    private function accountScopedRequest(string $method, string $endpoint, string $accountId, array $payload = [], array $extraQuery = []): array
    {
        $path = $this->accountPath($accountId, $endpoint);
        $payload = $this->withoutAccountId($payload);

        if ($extraQuery !== []) {
            $queryString = http_build_query(
                array_filter($extraQuery, static fn ($value) => $value !== null && $value !== ''),
                '',
                '&',
                PHP_QUERY_RFC3986
            );
            if ($queryString !== '') {
                $path .= (str_contains($path, '?') ? '&' : '?').$queryString;
            }
        }

        // Keep lock key available even though account_id is no longer in the payload.
        $payload['account_id'] = $accountId;
        $result = $this->request($method, $path, $payload);

        return $result;
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
    private function sanitizeHttpErrorDetail(string $responseText, int $status): string
    {
        $plain = trim(strip_tags($responseText));
        $plain = preg_replace('/\s+/', ' ', $plain) ?? $plain;

        if ($status >= 500 || stripos($plain, 'bad gateway') !== false || stripos($plain, 'gateway timeout') !== false) {
            return ': provider temporarily unavailable (HTTP '.$status.') — will retry';
        }

        return $plain !== '' ? ': '.substr($plain, 0, 200) : '';
    }

    private function errorHint(array $responseBody, int $status): ?string
    {
        $type = (string) ($responseBody['type'] ?? '');

        if ($type === 'errors/no_client_session') {
            return 'Unipile rejected the request because no active application session exists for your API key. '
                .'Open the Unipile Dashboard → Settings → API, set UNIPILE_BASE_URL=https://api.unipile.com/v2, '
                .'generate a fresh Access Token for UNIPILE_API_KEY, then run `php artisan config:clear`. '
                .'For local UI testing without Unipile, set UNIPILE_MOCK=true.';
        }

        if ($type === 'errors/invalid_parameters' && str_contains((string) ($responseBody['detail'] ?? ''), 'account_id')) {
            return 'Unipile v2 expects account_id in the URL path (/{account_id}/...), not as a query or body field. '
                .'Re-link the channel in Integrations if the stored account id is from API v1.';
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
                    ? 'Messaging service is reachable.'
                    : 'Messaging service returned HTTP '.$response->status().'.',
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
                'message' => 'Messaging error (HTTP '.$status.').',
                'hint' => $this->errorHint(is_array($body) ? $body : [], $status),
            ];
        }
    }

    public function createHostedAuthLink(array $context = []): array
    {
        $redirectUri = (string) (
            Arr::get($context, 'redirect_uri')
            ?? Arr::get($context, 'success_redirect_url')
            ?? config('app.url').'/integrations?connected=1'
        );

        $reconnectAccount = trim((string) (
            Arr::get($context, 'account_id')
            ?? Arr::get($context, 'reconnect_account')
            ?? ''
        ));
        $type = strtolower((string) Arr::get($context, 'type', $reconnectAccount !== '' ? 'reconnect' : 'create'));
        $isReconnect = $type === 'reconnect' && $reconnectAccount !== '';

        // Unipile v2 Create Auth Link is a strict anyOf:
        // - create: providers + expires_on + redirect_uri (+ optional state/config)
        // - reconnect: account_id + expires_on + redirect_uri (+ optional state/config)
        // v1 fields like notify_url / name / success_redirect_url are rejected as additionalProperties.
        $payload = array_filter([
            'expires_on' => now()->utc()->addHours(2)->format('Y-m-d\TH:i:s.v\Z'),
            'redirect_uri' => $redirectUri,
            'state' => Arr::get($context, 'state'),
            'config' => is_array(Arr::get($context, 'config')) ? Arr::get($context, 'config') : null,
            'account_scope_id' => Arr::get($context, 'account_scope_id'),
            'domain' => Arr::get($context, 'domain'),
        ], fn ($value) => $value !== null && $value !== []);

        if ($isReconnect) {
            $payload['account_id'] = $reconnectAccount;
        } else {
            $payload['providers'] = $this->normalizeHostedProviders(Arr::get($context, 'provider', 'linkedin'));
        }

        $result = $this->request('POST', $this->endpoint('hosted_auth_link'), $payload);

        // Normalize response so callers can keep reading `url`.
        $link = (string) ($result['link'] ?? $result['url'] ?? $result['hosted_url'] ?? '');
        if ($link !== '') {
            $result['link'] = $link;
            $result['url'] = $link;
        }

        return $result;
    }

    /**
     * Unipile v2 hosted auth: lowercase provider names, or wildcards like *:EMAILS / *:MESSAGING / *:CALENDAR.
     *
     * @return string|array<int, string>
     */
    private function normalizeHostedProviders(mixed $provider): string|array
    {
        if (is_array($provider)) {
            $normalized = array_values(array_filter(array_map(
                fn ($value) => $this->normalizeProviderToken((string) $value),
                $provider
            )));

            if ($normalized === []) {
                return '*:MESSAGING';
            }

            return count($normalized) === 1 ? $normalized[0] : $normalized;
        }

        $value = $this->normalizeProviderToken((string) $provider);
        if ($value === '' || $value === '*') {
            return '*';
        }

        // Wildcards like *:EMAILS must be sent as a string, not wrapped in an array.
        if (str_contains($value, ':')) {
            return $value;
        }

        return $value;
    }

    private function normalizeProviderToken(string $provider): string
    {
        $raw = trim($provider);
        if ($raw === '') {
            return '';
        }

        if ($raw === '*' || str_starts_with($raw, '*:')) {
            // Legacy v1 wildcard *:MAILING → v2 *:EMAILS
            if (strtoupper($raw) === '*:MAILING') {
                return '*:EMAILS';
            }

            // Keep wildcard suffix casing as Unipile documents (EMAILS/MESSAGING/CALENDAR/SOCIAL).
            if (str_starts_with($raw, '*:')) {
                $suffix = strtoupper(substr($raw, 2));

                return '*:'.$suffix;
            }

            return '*';
        }

        $lower = strtolower($raw);

        return match ($lower) {
            'google_oauth', 'gmail' => 'google',
            'mail', 'icloud' => 'imap',
            'microsoft' => 'outlook',
            'x' => 'twitter',
            default => $lower,
        };
    }

    /**
     * Connect a LinkedIn account using the li_at session cookie (Custom Auth / auth intent).
     * Unipile strongly recommends passing the user_agent from the same browser.
     */
    public function connectWithCookie(string $liAt, string $userAgent, array $options = []): array
    {
        $payload = array_filter([
            'provider' => 'linkedin',
            'access_token' => $liAt,
            'user_agent' => $userAgent,
            'country' => Arr::get($options, 'country'),
            'proxy' => Arr::get($options, 'proxy'),
            'account_id' => Arr::get($options, 'account_id'),
        ], fn ($v) => $v !== null && $v !== '');

        return $this->request('POST', $this->endpoint('auth_intent'), $payload);
    }

    /**
     * Connect a LinkedIn account using email + password credentials (auth intent).
     */
    public function connectWithCredentials(string $email, string $password, array $options = []): array
    {
        $payload = array_filter([
            'provider' => 'linkedin',
            'username' => $email,
            'password' => $password,
            'country' => Arr::get($options, 'country'),
            'account_id' => Arr::get($options, 'account_id'),
        ], fn ($v) => $v !== null && $v !== '');

        return $this->request('POST', $this->endpoint('auth_intent'), $payload);
    }

    /**
     * Solve a custom-auth checkpoint (2FA / OTP) using the intent_id from auth/intent.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function solveAuthCheckpoint(string $intentId, array $payload = []): array
    {
        return $this->request('POST', $this->endpoint('auth_checkpoint'), array_filter(array_merge([
            'intent_id' => $intentId,
        ], $payload), fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * Delete / disconnect a Unipile account.
     */
    public function disconnectAccount(string $accountId): array
    {
        return $this->request('DELETE', sprintf($this->endpoint('delete_account'), rawurlencode($accountId)));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function sendEmail(array $payload, array $context = []): array
    {
        $accountId = $this->resolveAccountId($payload, $context);
        $files = (array) Arr::pull($payload, '_files', []);
        $body = $this->normalizeEmailSendBody($this->withoutAccountId($payload));

        if ($files !== []) {
            $attachments = [];
            foreach ($files as $file) {
                if (! is_array($file)) {
                    continue;
                }
                $encoded = $this->encodeAttachmentFile($file, 'content');
                if ($encoded !== null) {
                    $attachments[] = $encoded;
                }
            }
            if ($attachments !== []) {
                $body['attachments'] = $attachments;
            }
        }

        return $this->accountScopedRequest(
            'POST',
            $this->endpoint('send_email'),
            $accountId,
            array_filter($body, fn ($value) => $value !== null && $value !== ''),
        );
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function listEmails(array $query = [], array $context = []): array
    {
        $accountId = $this->resolveAccountId($query, $context);
        $query = $this->withoutAccountId($query);

        return $this->accountScopedRequest('GET', $this->endpoint('list_emails'), $accountId, $query);
    }

    public function listInvitations(array $context = []): array
    {
        $accountId = $this->resolveAccountId([], $context);
        $type = strtolower(trim((string) ($context['type'] ?? 'received')));
        if (! in_array($type, ['sent', 'received'], true)) {
            $type = 'received';
        }

        // v2 requires type=sent|received.
        return $this->accountScopedRequest(
            'GET',
            $this->endpoint('list_invitations'),
            $accountId,
            ['type' => $type],
        );
    }

    public function acceptInvitation(string $invitationId, array $context = []): array
    {
        $accountId = $this->resolveAccountId([], $context);

        return $this->accountScopedRequest(
            'POST',
            $this->endpoint('accept_invitation').'/'.rawurlencode($invitationId).'/accept',
            $accountId
        );
    }

    public function withdrawInvitation(string $invitationId, array $context = []): array
    {
        $accountId = $this->resolveAccountId([], $context);
        $endpoint = sprintf($this->endpoint('withdraw_invitation'), rawurlencode($invitationId));

        // v2: POST /users/me/relation-requests/{request_id}/cancel (DELETE removed).
        return $this->accountScopedRequest('POST', $endpoint, $accountId);
    }

    public function listAccounts(string $ownerId = ''): array
    {
        $query = array_filter([
            'limit' => 100,
        ]);

        return $this->request('GET', $this->endpoint('list_accounts'), $query);
    }

    public function getAccount(string $accountId): array
    {
        return $this->request('GET', sprintf($this->endpoint('get_account'), rawurlencode($accountId)));
    }

    /**
     * Reconnect via Unipile v2 auth intent (cookie/credentials callers pass context fields).
     *
     * @param  array<string, mixed>  $context
     */
    public function reconnectAccount(string $accountId, array $context = []): array
    {
        $payload = array_filter(array_merge($context, [
            'account_id' => $accountId,
            'provider' => $this->normalizeProviderToken((string) ($context['provider'] ?? 'linkedin')),
        ]), fn ($v) => $v !== null && $v !== '');

        return $this->request('POST', $this->endpoint('auth_intent'), $payload);
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

        $requestedLimit = max(1, min(100, (int) ($filters['limit'] ?? 20)));

        // Always send count — Unipile defaults to ~10 when omitted.
        // Cap at 100 per search to stay within LinkedIn/Unipile rate limits.
        $apiFilters['count'] = $requestedLimit;

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

        // v2: POST /linkedin/search/people — api/category body fields removed.
        $payload = array_filter($apiFilters, fn ($v) => $v !== null && $v !== '' && $v !== []);

        return $this->searchUntilLimit(
            $accountId,
            $payload,
            $requestedLimit,
            $this->endpoint('search_people'),
        );
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
     * Resolve LinkedIn classic search parameter suggestions (location, company, etc.).
     *
     * @return list<array{id: string, label: string}>
     */
    public function listClassicSearchParameters(string $accountId, string $type, string $keywords, int $limit = 20): array
    {
        $type = strtoupper(trim($type));
        $keywords = trim($keywords);
        $limit = max(1, min(100, $limit));

        if ($type === '' || $keywords === '') {
            return [];
        }

        try {
            $result = $this->accountScopedRequest('GET', $this->endpoint('search').'/parameters', $accountId, [
                'type' => $type,
                'keywords' => $keywords,
                'limit' => $limit,
            ]);
        } catch (\Throwable) {
            return [];
        }

        $items = Arr::get($result, 'items', []);
        if (! is_array($items)) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = $item['id'] ?? null;
            if ($id === null || $id === '') {
                continue;
            }

            $label = trim((string) (
                $item['title']
                ?? $item['name']
                ?? $item['label']
                ?? $item['text']
                ?? ''
            ));

            if ($label === '') {
                $label = (string) $id;
            }

            $out[] = [
                'id' => (string) $id,
                'label' => $label,
            ];

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function resolveClassicSearchParameterIds(string $accountId, string $type, string $keywords, int $limit = 3): array
    {
        return array_values(array_map(
            static fn (array $row) => $row['id'],
            $this->listClassicSearchParameters($accountId, $type, $keywords, $limit),
        ));
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

        $requestedLimit = max(1, min(100, $limit));

        $payload = array_filter([
            'url'   => $url,
            'count' => $requestedLimit,
        ]);

        // From-URL search stays on POST /linkedin/search.
        return $this->searchUntilLimit($accountId, $payload, $requestedLimit, $this->endpoint('search'));
    }

    /**
     * Page LinkedIn search until the requested limit.
     * v2 LinkedIn search uses offset (cursor removed).
     *
     * @param  array<string, mixed>  $basePayload
     * @return array<string, mixed>
     */
    private function searchUntilLimit(
        string $accountId,
        array $basePayload,
        int $requestedLimit,
        ?string $searchPath = null,
    ): array {
        $searchPath ??= $this->endpoint('search');
        $target = max(1, min(100, $requestedLimit));
        $allItems = [];
        $seenIds = [];
        $offset = 0;
        $lastResponse = [];
        $maxPages = 12;

        for ($page = 1; $page <= $maxPages; $page++) {
            $remaining = $target - count($allItems);
            if ($remaining <= 0) {
                break;
            }

            $payload = $basePayload;
            $payload['count'] = max(1, min(100, (int) ($payload['count'] ?? $remaining), $remaining));
            $payload['offset'] = $offset;
            unset($payload['cursor'], $payload['api'], $payload['category']);

            $response = $this->accountScopedRequest('POST', $searchPath, $accountId, $payload);
            $lastResponse = $response;
            $items = Arr::get($response, 'items', Arr::get($response, 'data.items', Arr::get($response, 'data', [])));
            $items = is_array($items) ? array_values(array_filter($items, 'is_array')) : [];

            if ($items === []) {
                break;
            }

            $pageAdded = 0;
            foreach ($items as $item) {
                $id = (string) (
                    Arr::get($item, 'provider_id')
                    ?? Arr::get($item, 'id')
                    ?? Arr::get($item, 'public_identifier')
                    ?? ''
                );

                if ($id !== '') {
                    if (isset($seenIds[$id])) {
                        continue;
                    }
                    $seenIds[$id] = true;
                }

                $allItems[] = $item;
                $pageAdded++;
                if (count($allItems) >= $target) {
                    break 2;
                }
            }

            if ($pageAdded === 0) {
                break;
            }

            $offset += count($items);
        }

        $allItems = array_slice($allItems, 0, $target);

        if (isset($lastResponse['items']) && is_array($lastResponse['items'])) {
            $lastResponse['items'] = $allItems;
        } elseif (isset($lastResponse['data']['items']) && is_array($lastResponse['data']['items'])) {
            $lastResponse['data']['items'] = $allItems;
        } elseif (isset($lastResponse['data']) && is_array($lastResponse['data'])) {
            $lastResponse['data'] = $allItems;
            $lastResponse['items'] = $allItems;
        } else {
            $lastResponse['items'] = $allItems;
        }

        return $lastResponse;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function extractSearchCursor(array $response): ?string
    {
        $candidates = [
            Arr::get($response, 'next_cursor'),
            Arr::get($response, 'cursor'),
            Arr::get($response, 'next'),
            Arr::get($response, 'paging.next_cursor'),
            Arr::get($response, 'paging.cursor'),
            Arr::get($response, 'pagination.next_cursor'),
            Arr::get($response, 'pagination.cursor'),
            Arr::get($response, 'data.next_cursor'),
            Arr::get($response, 'data.cursor'),
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Fetch a LinkedIn company profile by public slug, numeric ID, or URN.
     *
     * @return array<string, mixed>
     */
    public function getCompanyProfile(string $identifier, string $accountId): array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            throw new UnipileException('Company identifier is required.', 422);
        }

        return $this->accountScopedRequest('GET', '/linkedin/company/'.rawurlencode($identifier), $accountId);
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
        $profile = $this->accountScopedRequest('GET', '/users/'.rawurlencode($identifier), $accountId);

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
        $memberId = $this->extractLinkedInProviderId($profile);
        $providerId = (string) (
            $memberId !== '' ? $memberId : (
                Arr::get($profile, 'provider_id')
                ?? Arr::get($profile, 'id')
                ?? ''
            )
        );
        $publicId = (string) (
            Arr::get($profile, 'public_identifier')
            ?? $fallbackIdentifier
            ?? ''
        );

        if ($providerId !== '' && $this->isLinkedInVanitySlug($providerId)) {
            $providerId = $memberId;
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
     * WhatsApp public IDs must be `{digits}@s.whatsapp.net` (country code, no +).
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
     * Format a phone for WhatsApp profile lookup / chat start.
     * Example: +1 555 123 4567 → 15551234567@s.whatsapp.net
     */
    public function whatsappIdentifierFromPhone(string $phone): string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return '';
        }

        if (str_contains($phone, '@')) {
            return $phone;
        }

        $digits = $this->normalizePhone($phone);

        return $digits !== '' ? $digits.'@s.whatsapp.net' : '';
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

        $payload = array_filter($filters, fn ($v) => $v !== null && $v !== '' && $v !== []);
        unset($payload['api'], $payload['category']);

        if (! empty($payload['has_job_offers']) && ! isset($payload['has_job_postings'])) {
            $payload['has_job_postings'] = (bool) $payload['has_job_offers'];
            unset($payload['has_job_offers']);
        }

        if (! $accountId) {
            $accountId = $this->resolveAccountId([], $context);
        }

        return $this->accountScopedRequest(
            'POST',
            $this->endpoint('search_companies'),
            (string) $accountId,
            $payload,
        );
    }

    /**
     * LinkedIn content search — used for competitor company posts.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $context
     */
    public function searchPosts(array $filters, array $context = []): array
    {
        $accountId = Arr::pull($context, 'account_id');
        Arr::forget($context, ['owner_id', 'organization_id']);

        $payload = array_filter($filters, fn ($v) => $v !== null && $v !== '' && $v !== []);
        unset($payload['api'], $payload['category']);

        if (! $accountId) {
            $accountId = $this->resolveAccountId([], $context);
        }

        return $this->accountScopedRequest(
            'POST',
            $this->endpoint('search_posts'),
            (string) $accountId,
            $payload,
        );
    }

    public function sendInvitation(array $payload, array $context = []): array
    {
        $accountId = $this->resolveAccountId($payload, $context);
        $providerId = (string) (
            Arr::get($payload, 'provider_id')
            ?? Arr::get($payload, 'user_id')
            ?? Arr::get($payload, 'recipient_id')
            ?? ''
        );

        if ($providerId === '') {
            throw new UnipileException('provider_id or recipient_id is required for invitations.');
        }

        $providerId = $this->ensureLinkedInProviderId(
            $providerId,
            array_merge($context, ['account_id' => $accountId]),
        );

        // v2: POST /{account_id}/users/me/relation-requests with user_id (was provider_id).
        return $this->accountScopedRequest('POST', $this->endpoint('send_invitation'), $accountId, array_filter([
            'user_id' => $providerId,
            'message' => Arr::get($payload, 'message'),
        ], fn ($value) => $value !== null && $value !== ''));
    }

    public function listSentInvitations(array $filters = [], array $context = []): array
    {
        $accountId = $this->resolveAccountId($filters, $context);
        $filters = $this->withoutAccountId(array_merge($filters, $context));
        Arr::forget($filters, ['owner_id', 'organization_id']);

        return $this->accountScopedRequest(
            'GET',
            $this->endpoint('list_invitations'),
            $accountId,
            // v2 requires query param type=sent|received (was filter).
            array_merge($filters, ['type' => 'sent'])
        );
    }

    public function listReceivedInvitations(array $filters = [], array $context = []): array
    {
        $accountId = $this->resolveAccountId($filters, $context);
        $filters = $this->withoutAccountId(array_merge($filters, $context));
        Arr::forget($filters, ['owner_id', 'organization_id']);

        return $this->accountScopedRequest(
            'GET',
            $this->endpoint('list_invitations'),
            $accountId,
            array_merge($filters, ['type' => 'received'])
        );
    }

    public function handleReceivedInvitation(string $invitationId, string $action, array $context = []): array
    {
        $accountId = (string) ($context['account_id'] ?? '');

        if ($accountId === '') {
            throw new UnipileException('account_id is required.');
        }

        // v2: dedicated accept/cancel routes — shared_secret / action body removed.
        $suffix = match ($action) {
            'reject', 'decline', 'cancel' => 'cancel',
            default => 'accept',
        };

        return $this->accountScopedRequest(
            'POST',
            $this->endpoint('list_invitations').'/'.rawurlencode($invitationId).'/'.$suffix,
            $accountId
        );
    }

    public function cancelInvitation(string $invitationId, array $context = []): array
    {
        $accountId = (string) ($context['account_id'] ?? '');
        if ($accountId === '') {
            throw new UnipileException('account_id is required.');
        }

        return $this->accountScopedRequest(
            'POST',
            sprintf($this->endpoint('withdraw_invitation'), rawurlencode($invitationId)),
            $accountId
        );
    }

    public function listChats(array $filters = [], array $context = []): array
    {
        $accountId = $this->resolveAccountId($filters, $context);
        $filters = $this->withoutAccountId(array_merge($filters, $context));
        Arr::forget($filters, ['owner_id', 'organization_id', 'account_type']);
        if (array_key_exists('unread', $filters)) {
            $filters['is_unread'] = (bool) $filters['unread'];
            unset($filters['unread']);
        }

        return $this->accountScopedRequest('GET', $this->endpoint('list_chats'), $accountId, $filters);
    }

    public function listMessages(string $chatId, array $filters = [], array $context = []): array
    {
        $accountId = $this->resolveAccountId($filters, $context);
        $filters = $this->withoutAccountId(array_merge($filters, $context));
        Arr::forget($filters, ['owner_id', 'organization_id']);
        if (isset($filters['sender_id']) && ! isset($filters['user_id'])) {
            $filters['user_id'] = $filters['sender_id'];
            unset($filters['sender_id']);
        }

        return $this->accountScopedRequest(
            'GET',
            sprintf($this->endpoint('list_messages'), rawurlencode($chatId)),
            $accountId,
            $filters
        );
    }

    public function sendMessage(string $chatId, array $payload, array $context = []): array
    {
        $accountId = $this->resolveAccountId($payload, $context);
        $endpoint = sprintf($this->endpoint('send_message'), rawurlencode($chatId));
        $files = (array) Arr::pull($payload, '_files', []);
        $body = $this->withoutAccountId(array_merge($payload, Arr::except($context, ['owner_id', 'organization_id', 'account_id', '_unipile_account_id'])));
        // v2 removed these v1 message fields.
        Arr::forget($body, ['thread_id', 'typing_duration', 'voice_message', 'video_message', 'chat_id']);

        if ($files !== []) {
            $attachments = [];
            foreach ($files as $file) {
                if (! is_array($file)) {
                    continue;
                }
                $encoded = $this->encodeAttachmentFile($file);
                if ($encoded !== null) {
                    $attachments[] = $encoded;
                }
            }
            if ($attachments !== []) {
                $body['attachments'] = $attachments;
            }
        }

        return $this->accountScopedRequest('POST', $endpoint, $accountId, array_filter(
            $body,
            fn ($value) => $value !== null && $value !== '' && $value !== []
        ));
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
            throw new UnipileException('Messaging API key is missing.', 500);
        }

        Log::debug('[Unipile] → POST '.$endpoint.' (multipart message)', [
            'fields' => array_keys($fields),
            'files' => count($files),
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
                'Messaging error (HTTP '.$status.')'.$this->sanitizeHttpErrorDetail(
                    (string) ($exception->response?->body() ?? $exception->getMessage()),
                    $status,
                ),
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
            throw new UnipileException('Messaging API key is missing.', 500);
        }

        $accountId = $this->resolveAccountId([], $context);
        $chatId = trim((string) ($context['chat_id'] ?? ''));
        if ($chatId === '') {
            throw new UnipileException('chat_id is required to download a message attachment on Unipile v2.', 422);
        }

        $endpoint = $this->accountPath(
            $accountId,
            '/chats/'.rawurlencode($chatId).'/messages/'.rawurlencode($messageId).'/attachments/'.rawurlencode($attachmentId)
        );

        return Http::baseUrl($this->baseUrl())
            ->withHeaders(['X-API-KEY' => $this->apiKey()])
            ->timeout(120)
            ->get($endpoint);
    }

    /**
     * Stream an email attachment from Unipile (different path than chat attachments).
     */
    public function downloadEmailAttachment(string $emailId, string $attachmentId, array $context = []): \Illuminate\Http\Client\Response
    {
        if ($this->apiKey() === '') {
            throw new UnipileException('Messaging API key is missing.', 500);
        }

        $accountId = $this->resolveAccountId([], $context);
        $endpoint = $this->accountPath(
            $accountId,
            '/emails/'.rawurlencode($emailId).'/attachments/'.rawurlencode($attachmentId)
        );

        return Http::baseUrl($this->baseUrl())
            ->withHeaders(['X-API-KEY' => $this->apiKey()])
            ->timeout(120)
            ->get($endpoint);
    }

    public function startChat(array $payload, array $context = []): array
    {
        $accountId = $this->resolveAccountId($payload, $context);
        $userIds = Arr::get($payload, 'users_ids')
            ?? Arr::get($payload, 'attendees_ids')
            ?? Arr::get($payload, 'attendee_ids')
            ?? [];

        if (! is_array($userIds)) {
            $userIds = [$userIds];
        }

        $userIds = array_values(array_filter(array_map('strval', $userIds)));
        if ($userIds === []) {
            $fallback = (string) (Arr::get($payload, 'provider_id') ?? Arr::get($payload, 'recipient_id') ?? '');
            if ($fallback !== '') {
                $userIds = [$fallback];
            }
        }

        if ($userIds === []) {
            throw new UnipileException('users_ids is required to start a chat.');
        }

        $resolveContext = array_merge($context, ['account_id' => $accountId]);
        $userIds = array_values(array_map(
            fn (string $id) => $this->isLinkedInVanitySlug($id)
                ? $this->ensureLinkedInProviderId($id, $resolveContext)
                : $id,
            $userIds,
        ));

        // v2: a single string starts a 1:1 chat; an array starts a group
        // (even when the array has only one id).
        $usersIdsPayload = count($userIds) === 1 ? $userIds[0] : $userIds;

        $specifics = Arr::get($payload, 'specifics');
        if (! is_array($specifics)) {
            $specifics = [];
        }
        // Some LinkedIn guides still show options{}; Start a Chat expects specifics{}.
        $legacyOptions = Arr::get($payload, 'options');
        if (is_array($legacyOptions) && $legacyOptions !== []) {
            $specifics = array_replace_recursive($specifics, $legacyOptions);
        }
        // v1 LinkedIn options lived under linkedin{}; v2 nests them under specifics.linkedin.
        $legacyLinkedIn = Arr::get($payload, 'linkedin');
        if (is_array($legacyLinkedIn) && $legacyLinkedIn !== []) {
            $specifics['linkedin'] = array_replace_recursive(
                is_array($specifics['linkedin'] ?? null) ? $specifics['linkedin'] : [],
                $this->normalizeLegacyLinkedInChatOptions($legacyLinkedIn),
            );
        }

        $body = array_filter([
            'users_ids' => $usersIdsPayload,
            'text' => Arr::get($payload, 'text'),
            'name' => Arr::get($payload, 'name') ?? Arr::get($payload, 'subject'),
            'specifics' => $specifics !== [] ? $specifics : null,
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);

        return $this->accountScopedRequest('POST', $this->endpoint('start_chat'), $accountId, $body);
    }

    /**
     * @param  array<string, mixed>  $linkedin
     * @return array<string, mixed>
     */
    private function normalizeLegacyLinkedInChatOptions(array $linkedin): array
    {
        $out = $linkedin;

        if (isset($out['invitation_id']) && ! isset($out['relation_request_id'])) {
            $out['relation_request_id'] = $out['invitation_id'];
            unset($out['invitation_id']);
        }
        if (isset($out['topic']) && ! isset($out['company_topic'])) {
            $out['company_topic'] = $out['topic'];
            unset($out['topic']);
        }

        // Prefer classic nesting when callers still send flat LinkedIn chat options.
        if (! isset($out['classic']) && ! isset($out['recruiter']) && ! isset($out['sales_navigator'])) {
            return ['classic' => $out];
        }

        return $out;
    }

    public function markChatReadState(string $chatId, bool $isRead, array $context = []): array
    {
        $accountId = $this->resolveAccountId([], $context);

        return $this->accountScopedRequest(
            'PATCH',
            $this->endpoint('list_chats').'/'.rawurlencode($chatId),
            $accountId,
            // v2 Update Chat expects boolean read_status (true=read, false=unread).
            ['read_status' => $isRead]
        );
    }

    public function getProfileByIdentifier(string $identifier, array $context = []): array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            throw new UnipileException('Profile identifier is required.');
        }

        $accountId = $this->resolveAccountId([], $context);
        $sections = $this->normalizeProfileSections(
            $context['with_sections'] ?? $context['linkedin_sections'] ?? null
        );
        $query = array_filter([
            'with_sections' => $sections,
            'notify' => $context['notify'] ?? null,
            'variant' => $context['variant'] ?? null,
            '_quiet' => ! empty($context['_quiet']) ? true : null,
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);

        return $this->accountScopedRequest('GET', '/users/'.rawurlencode($identifier), $accountId, $query);
    }

    public function listRelations(array $filters = [], array $context = []): array
    {
        $accountId = $this->resolveAccountId($filters, $context);
        $filters = $this->withoutAccountId(array_merge($filters, $context));
        Arr::forget($filters, ['owner_id', 'organization_id']);
        $userId = trim((string) Arr::pull($filters, 'user_id', 'me')) ?: 'me';

        // v2: GET /users/{user_id}/relations (use me for the connected account).
        return $this->accountScopedRequest(
            'GET',
            '/users/'.rawurlencode($userId).'/relations',
            $accountId,
            $filters,
        );
    }

    public function listFollowers(array $filters = [], array $context = []): array
    {
        $accountId = $this->resolveAccountId($filters, $context);
        $filters = $this->withoutAccountId(array_merge($filters, $context));
        Arr::forget($filters, ['owner_id', 'organization_id']);
        $userId = trim((string) Arr::pull($filters, 'user_id', 'me')) ?: 'me';

        return $this->accountScopedRequest(
            'GET',
            '/users/'.rawurlencode($userId).'/followers',
            $accountId,
            $filters,
        );
    }

    /**
     * Publish a post to LinkedIn via Unipile v2 (JSON + base64 attachments).
     *
     * @param  array{
     *     attachments?: list<array{path: string, filename?: string, mime?: string, field?: string}>,
     *     video_thumbnail?: array{path: string, filename?: string, mime?: string},
     *     repost?: string,
     *     quoted_post_id?: string,
     *     as_organization?: string,
     *     post_as?: string,
     * }  $options
     */
    public function createPost(string $accountId, string $content, array $options = []): array
    {
        // v2 renames: repost→quoted_post_id, as_organization→post_as; external_link removed.
        $body = array_filter([
            'text' => $content,
            'quoted_post_id' => Arr::get($options, 'quoted_post_id') ?? Arr::get($options, 'repost'),
            'post_as' => Arr::get($options, 'post_as') ?? Arr::get($options, 'as_organization'),
            'specifics' => Arr::get($options, 'specifics'),
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);

        $attachments = [];
        foreach ((array) Arr::get($options, 'attachments', []) as $attachment) {
            if (! is_array($attachment)) {
                continue;
            }
            $encoded = $this->encodeAttachmentFile($attachment);
            if ($encoded !== null) {
                $attachments[] = $encoded;
            }
        }
        if ($attachments !== []) {
            $body['attachments'] = $attachments;
        }

        $thumbnail = Arr::get($options, 'video_thumbnail');
        if (is_array($thumbnail)) {
            $encodedThumb = $this->encodeAttachmentFile($thumbnail);
            if ($encodedThumb !== null) {
                $body['video_thumbnail'] = $encodedThumb;
            }
        }

        return $this->accountScopedRequest('POST', $this->endpoint('create_post'), $accountId, $body);
    }

    public function listPosts(array $filters = [], array $context = []): array
    {
        $accountId = $this->resolveAccountId($filters, $context);
        $filters = $this->withoutAccountId(array_merge($filters, $context));
        Arr::forget($filters, ['owner_id', 'organization_id']);
        $userId = trim((string) Arr::pull($filters, 'user_id', 'me')) ?: 'me';

        // v2 has no GET /posts list; list a user's posts instead.
        return $this->accountScopedRequest(
            'GET',
            '/users/'.rawurlencode($userId).'/posts',
            $accountId,
            $filters,
        );
    }

    public function listUserPosts(string $identifier, array $filters = [], array $context = []): array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            throw new UnipileException('Company or user identifier is required to list posts.');
        }

        $accountId = $this->resolveAccountId($filters, $context);
        $filters = $this->withoutAccountId(array_merge($filters, $context));
        Arr::forget($filters, ['owner_id', 'organization_id']);

        return $this->accountScopedRequest(
            'GET',
            '/users/'.rawurlencode($identifier).'/posts',
            $accountId,
            $filters
        );
    }

    public function listPostComments(string $postId, array $filters = [], array $context = []): array
    {
        $accountId = $this->resolveAccountId($filters, $context);
        $filters = $this->withoutAccountId(array_merge($filters, $context));
        Arr::forget($filters, ['owner_id', 'organization_id']);

        return $this->accountScopedRequest(
            'GET',
            '/posts/'.$this->encodePostId($postId).'/comments',
            $accountId,
            $filters
        );
    }

    public function listPostReactions(string $postId, array $filters = [], array $context = []): array
    {
        $accountId = $this->resolveAccountId($filters, $context);
        $filters = $this->withoutAccountId(array_merge($filters, $context));
        Arr::forget($filters, ['owner_id', 'organization_id']);

        return $this->accountScopedRequest(
            'GET',
            '/posts/'.$this->encodePostId($postId).'/reactions',
            $accountId,
            $filters
        );
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
                'with_sections' => ['linkedin_skills'],
            ]);
            $skillId = $this->firstEndorseableSkillId($profile);
            if ($skillId === null) {
                $skillCount = $this->countProfileSkills($profile);
                Log::info('[Unipile] Endorse skipped — no endorseable skill', [
                    'profile_id' => $providerId,
                    'skills_seen' => $skillCount,
                    'network_distance' => Arr::get($profile, 'network_distance')
                        ?? Arr::get($profile, 'provider_data.network_distance')
                        ?? Arr::get($profile, 'specifics.linkedin.network_distance'),
                ]);

                throw new UnipileException(
                    $skillCount > 0
                        ? 'No endorseable skill on this profile. They may already be endorsed, or you need a 1st-degree connection.'
                        : 'No endorseable skill on this profile. Use 1st-degree connections with skills listed.',
                    422
                );
            }

            // v2: POST /linkedin/member/{member_id}/endorse-skill { skill_id }
            $result = $this->accountScopedRequest(
                'POST',
                '/linkedin/member/'.rawurlencode($providerId).'/endorse-skill',
                $accountId,
                ['skill_id' => (string) $skillId],
            );
            Log::info('[Unipile] Endorse succeeded', [
                'profile_id' => $providerId,
                'skill_id' => $skillId,
            ]);

            return $result;
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
            throw new UnipileException('LinkedIn unfollow is not supported.');
        }

        if ($action === 'like_post') {
            return $this->likeLatestUserPost($providerId, $accountId);
        }

        if ($action === 'comment_post') {
            $postId = trim((string) (Arr::get($payload, 'post_id') ?? ''));
            $text = trim((string) (Arr::get($payload, 'text') ?? 'Great insights.'));
            if ($postId === '') {
                throw new UnipileException('post_id is required to comment on a LinkedIn post.');
            }

            return $this->accountScopedRequest(
                'POST',
                '/posts/'.$this->encodePostId($postId).'/comments',
                $accountId,
                array_filter([
                    'text' => $text !== '' ? $text : 'Great insights.',
                ], fn ($value) => $value !== null && $value !== '')
            );
        }

        throw new UnipileException(
            "LinkedIn profile action [{$action}] is not supported on Unipile API v2.",
            422
        );
    }

    /**
     * Like the most recent public post for a LinkedIn profile.
     *
     * Unipile requires the post social_id (urn:li:activity:…) and reaction type
     * linkedin_like — not a /linkedin/user/{id} action named like_post.
     *
     * @return array<string, mixed>
     */
    public function likeLatestUserPost(string $providerId, string $accountId): array
    {
        $postsResponse = $this->listUserPosts($providerId, [
            'account_id' => $accountId,
            'limit' => 5,
        ], ['account_id' => $accountId]);

        $posts = Arr::get($postsResponse, 'items', Arr::get($postsResponse, 'data.items', Arr::get($postsResponse, 'data', [])));
        $posts = is_array($posts) ? array_values(array_filter($posts, 'is_array')) : [];
        if ($posts === []) {
            throw new UnipileException('No recent LinkedIn posts found to like for this profile.', 422);
        }

        $socialId = '';
        $picked = null;
        foreach ($posts as $post) {
            if (! is_array($post)) {
                continue;
            }

            $candidate = trim((string) (
                Arr::get($post, 'social_id')
                ?? Arr::get($post, 'id')
                ?? ''
            ));

            if ($candidate === '') {
                continue;
            }

            // Prefer social_id / urn forms Unipile expects for reactions.
            if (str_starts_with($candidate, 'urn:li:') || ctype_digit($candidate)) {
                $socialId = $candidate;
                $picked = $post;
                break;
            }

            if ($socialId === '') {
                $socialId = $candidate;
                $picked = $post;
            }
        }

        if ($socialId === '') {
            throw new UnipileException('No recent LinkedIn posts found to like for this profile.', 422);
        }

        $reaction = $this->reactToPost($socialId, 'linkedin_like', [
            'account_id' => $accountId,
        ]);

        Log::info('[Unipile] Liked latest post', [
            'profile_id' => $providerId,
            'social_id' => $socialId,
            'post_id' => Arr::get($picked, 'id'),
        ]);

        return [
            'action' => 'like_post',
            'social_id' => $socialId,
            'post' => $picked,
            'reaction' => $reaction,
        ];
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

        if (preg_match('~linkedin\.com/in/([^/?#]+)~i', $identifier, $matches)) {
            $identifier = $matches[1];
        }

        if (preg_match('/^(ACo|ADo|ACw|AE)/i', $identifier)) {
            return ['provider_id' => $identifier, 'profile' => []];
        }

        $profile = $this->getProfileByIdentifier($identifier, $context);
        $normalized = $this->normalizeProfileItem(is_array($profile) ? $profile : [], $identifier);
        $providerId = $this->extractLinkedInProviderId($normalized);

        return [
            'provider_id' => $providerId !== '' ? $providerId : null,
            'profile' => $normalized,
        ];
    }

    /**
     * LinkedIn invites and DMs require Unipile provider_id (ACo…), not vanity slugs.
     *
     * @param  array<string, mixed>  $context
     */
    private function ensureLinkedInProviderId(string $providerId, array $context): string
    {
        $providerId = trim($providerId);
        if ($providerId === '') {
            throw new UnipileException('provider_id or recipient_id is required.');
        }

        if (! $this->isLinkedInVanitySlug($providerId)) {
            return $providerId;
        }

        $resolved = $this->resolveProviderId($providerId, $context);
        $resolvedId = trim((string) ($resolved['provider_id'] ?? ''));
        if ($resolvedId === '' || $this->isLinkedInVanitySlug($resolvedId)) {
            throw new UnipileException(
                'Could not resolve LinkedIn profile to a valid provider ID. Use a profile URL or LinkedIn member id (ACo…).',
                422,
            );
        }

        return $resolvedId;
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function extractLinkedInProviderId(array $profile): string
    {
        foreach (['provider_id', 'id'] as $key) {
            $value = trim((string) (Arr::get($profile, $key) ?? ''));
            if ($this->isLinkedInMemberId($value)) {
                return $value;
            }
        }

        foreach (['member_urn', 'object_urn', 'linkedin_id', 'invitee_id'] as $key) {
            $extracted = $this->linkedinMemberIdFromUrn((string) (Arr::get($profile, $key) ?? ''));
            if ($extracted !== '') {
                return $extracted;
            }
        }

        return '';
    }

    private function isLinkedInMemberId(string $value): bool
    {
        $value = trim($value);

        return $value !== '' && preg_match('/^(ACo|ADo|ACw|AE)/i', $value);
    }

    private function linkedinMemberIdFromUrn(string $urn): string
    {
        $urn = trim($urn);
        if ($urn === '') {
            return '';
        }

        if (preg_match('/:(ACo[^:\s]+|ADo[^:\s]+|ACw[^:\s]+|AE[^:\s]+)$/i', $urn, $matches)) {
            return $matches[1];
        }

        if (preg_match('/\b(ACo[a-zA-Z0-9_-]+|ADo[a-zA-Z0-9_-]+|ACw[a-zA-Z0-9_-]+|AE[a-zA-Z0-9_-]+)\b/', $urn, $matches)) {
            return $matches[1];
        }

        return '';
    }

    private function isLinkedInVanitySlug(string $identifier): bool
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return false;
        }

        if (preg_match('/^(ACo|ADo|ACw|AE)/i', $identifier)) {
            return false;
        }

        if (preg_match('~linkedin\.com~i', $identifier)) {
            return true;
        }

        if (str_starts_with($identifier, '+') || str_contains($identifier, '@')) {
            return false;
        }

        if (preg_match('/^\d+$/', $identifier)) {
            return false;
        }

        return (bool) preg_match('/^[a-z0-9_-]{3,}$/i', $identifier);
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

        throw new UnipileException('No connected messaging account available for this request.');
    }

    /**
     * @param array<string, mixed> $profile
     */
    private function firstEndorseableSkillId(array $profile): ?string
    {
        foreach ($this->extractProfileSkills($profile) as $skill) {
            if (! is_array($skill)) {
                continue;
            }

            // Already endorsed by this account — skip.
            if (Arr::get($skill, 'endorsed') === true || Arr::get($skill, 'endorsed') === 1) {
                continue;
            }

            $endorsementId = Arr::get($skill, 'endorsement_id')
                ?? Arr::get($skill, 'skill_endorsement_id')
                ?? Arr::get($skill, 'skill_id')
                ?? Arr::get($skill, 'id')
                ?? Arr::get($skill, 'endorsementId');

            if ($endorsementId === null || $endorsementId === '' || $endorsementId === false) {
                continue;
            }

            $id = trim((string) $endorsementId);
            if ($id !== '' && $id !== '0') {
                return $id;
            }
        }

        return null;
    }

    /**
     * Map v1 linkedin_sections aliases onto v2 with_sections values.
     *
     * @return list<string>|null
     */
    private function normalizeProfileSections(mixed $sections): ?array
    {
        if ($sections === null || $sections === '' || $sections === []) {
            return null;
        }

        if ($sections === '*' || $sections === 'linkedin_*') {
            return ['linkedin_*'];
        }

        if (! is_array($sections)) {
            $sections = [$sections];
        }

        $normalized = [];
        foreach ($sections as $section) {
            $section = trim((string) $section);
            if ($section === '' || $section === '*') {
                $normalized[] = 'linkedin_*';
                continue;
            }
            if (str_starts_with($section, 'linkedin_')) {
                $normalized[] = $section;
                continue;
            }
            $normalized[] = 'linkedin_'.$section;
        }

        $normalized = array_values(array_unique($normalized));

        return $normalized !== [] ? $normalized : null;
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return list<mixed>
     */
    private function extractProfileSkills(array $profile): array
    {
        $candidates = [
            Arr::get($profile, 'skills'),
            Arr::get($profile, 'skills_preview'),
            Arr::get($profile, 'data.skills'),
            Arr::get($profile, 'data.skills_preview'),
            Arr::get($profile, 'provider_data.skills'),
            Arr::get($profile, 'sections.skills'),
            Arr::get($profile, 'specifics.linkedin.skills'),
            Arr::get($profile, 'specifics.skills'),
        ];

        foreach ($candidates as $skills) {
            if (! is_array($skills) || $skills === []) {
                continue;
            }

            // Some payloads wrap items under { items: [...] }.
            if (isset($skills['items']) && is_array($skills['items'])) {
                return array_values($skills['items']);
            }

            // List of skill objects.
            if (array_is_list($skills)) {
                return $skills;
            }

            // Single skill object (associative).
            if (Arr::hasAny($skills, ['endorsement_id', 'skill_endorsement_id', 'endorsementId', 'name', 'title'])) {
                return [$skills];
            }

            return array_values($skills);
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function countProfileSkills(array $profile): int
    {
        return count($this->extractProfileSkills($profile));
    }

    public function reactToPost(string $postId, string $reaction, array $context = []): array
    {
        $postId = trim($postId);
        if ($postId === '') {
            throw new UnipileException('post_id is required to react to a LinkedIn post.');
        }

        $accountId = $this->resolveAccountId([], $context);

        return $this->accountScopedRequest(
            'POST',
            '/posts/'.$this->encodePostId($postId).'/reactions',
            $accountId,
            ['reaction' => $this->normalizeLinkedInReaction($reaction)]
        );
    }

    private function normalizeLinkedInReaction(string $reaction): string
    {
        $normalized = strtolower(trim($reaction));

        return match ($normalized) {
            '', 'like', 'linkedin_like' => 'linkedin_like',
            'celebrate', 'linkedin_celebrate' => 'linkedin_celebrate',
            'support', 'linkedin_support' => 'linkedin_support',
            'love', 'linkedin_love' => 'linkedin_love',
            'insightful', 'linkedin_insightful', 'linkedin_insighful' => 'linkedin_insightful',
            'funny', 'linkedin_funny' => 'linkedin_funny',
            default => str_starts_with($normalized, 'linkedin_') ? $normalized : 'linkedin_like',
        };
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
            ?? Arr::get($inner, 'chat_provider_id')
            ?? Arr::get($payload, 'chat_id')
            ?? Arr::get($payload, 'data.chat_id')
            ?? ''
        );
        $messageId = (string) (
            Arr::get($inner, 'id')
            ?? Arr::get($inner, 'provider_id')
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
        $accountProvider = (string) (
            Arr::get($payload, 'account_provider')
            ?? Arr::get($payload, 'account_type')
            ?? Arr::get($inner, 'provider')
            ?? Arr::get($payload, 'provider')
            ?? ''
        );

        return array_merge($payload, [
            'account_id' => $accountId !== '' ? $accountId : ($payload['account_id'] ?? null),
            'account_provider' => $accountProvider !== '' ? $accountProvider : ($payload['account_provider'] ?? null),
            'provider' => $accountProvider !== '' ? $accountProvider : ($payload['provider'] ?? null),
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
                    'account_provider' => $accountProvider !== '' ? $accountProvider : null,
                    'provider' => $accountProvider !== '' ? $accountProvider : null,
                ], fn ($v) => $v !== null && $v !== '')
            ),
        ]);
    }

    public function eventType(array $payload): string
    {
        $raw = (string) (Arr::get($payload, 'type') ?? Arr::get($payload, 'event', 'unknown'));

        // Normalize Unipile v2 event names to the internal types ProcessUnipileWebhookEventJob expects.
        return match ($raw) {
            // Messaging (v1 + v2)
            'message.new', 'message_received', 'new_message' => $this->messageNewDirection($payload),
            'message_read', 'message.receipt.read' => 'chat.read',
            'message_reaction', 'message.reaction.new' => 'message.reaction',
            'message_delivered', 'message.delivery', 'message.receipt.delivery' => 'message.delivered',
            'message_edited', 'message.update' => 'message.sent',
            'message_deleted', 'message.delete' => 'message.delete',

            // Users / relations (v1 new_relation → v2 relation.new)
            'new_relation', 'users.new_relation', 'relation.new' => 'new_relation',
            'follower.new' => 'relation.followed',

            // Account status (v1 ACCOUNT_STATUS → v2 account.*)
            'account.status.disconnected' => 'account.disconnected',
            'account.status.errored' => 'account.error',
            'account.status.running' => 'account.status.running',
            'account.reconnect' => 'account.reconnected',
            'account.remove' => 'account.disconnected',
            'account.add' => 'account.add',

            // Email (v1 mail_* → v2 email.* / tracking.*)
            'email.new', 'mail_received' => $this->emailNewDirection($payload),
            'email.new.bounce', 'mail_bounced' => 'email.bounced',
            'tracking.open', 'mail_opened' => 'email.opened',
            'tracking.click', 'mail_clicked' => 'email.clicked',
            'mail_sent' => 'email.sent',

            // Calendar (v2)
            'calendar.event.new', 'calendar.event.created', 'event.created' => 'calendar.event.created',
            'calendar.event.update', 'calendar.event.updated', 'event.updated' => 'calendar.event.updated',

            default => str_replace('_', '.', $raw),
        };
    }

    /**
     * Unipile v2 replaced mail_received/mail_sent with email.new + folder role.
     *
     * @param  array<string, mixed>  $payload
     */
    private function emailNewDirection(array $payload): string
    {
        $inner = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];
        $folders = Arr::get($inner, 'folders')
            ?? Arr::get($payload, 'folders')
            ?? Arr::get($payload, 'data.folders')
            ?? [];

        if (is_array($folders)) {
            foreach ($folders as $folder) {
                if (! is_array($folder)) {
                    continue;
                }
                $role = strtoupper(trim((string) ($folder['role'] ?? '')));
                if ($role === 'SENT') {
                    return 'email.sent';
                }
                if ($role === 'INBOX') {
                    return 'mail.received';
                }
            }
        }

        $role = strtoupper(trim((string) (
            Arr::get($inner, 'folder.role')
            ?? Arr::get($payload, 'folder.role')
            ?? Arr::get($inner, 'role')
            ?? ''
        )));

        if ($role === 'SENT') {
            return 'email.sent';
        }

        // Default to inbound so replies still land in the inbox when folder metadata is missing.
        return 'mail.received';
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

    /**
     * @return array<string, mixed>
     */
    public function listCalendars(string $accountId, int $limit = 20): array
    {
        return $this->accountScopedRequest('GET', $this->endpoint('list_calendars'), $accountId, [
            'limit' => max(1, min($limit, 100)),
        ]);
    }

    public function updateCalendarEvent(string $accountId, string $calendarId, string $eventId, array $body): array
    {
        $endpoint = sprintf(
            $this->endpoint('calendar_event'),
            rawurlencode($calendarId),
            rawurlencode($eventId),
        );

        return $this->accountScopedRequest(
            'PATCH',
            $endpoint,
            $accountId,
            $this->normalizeCalendarEventBody($body)
        );
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function createCalendarEvent(string $accountId, string $calendarId, array $body): array
    {
        $endpoint = sprintf($this->endpoint('calendar_events'), rawurlencode($calendarId));

        return $this->accountScopedRequest(
            'POST',
            $endpoint,
            $accountId,
            $this->normalizeCalendarEventBody($body)
        );
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function listCalendarEvents(string $accountId, string $calendarId, array $query = []): array
    {
        $endpoint = sprintf($this->endpoint('calendar_events'), rawurlencode($calendarId));

        return $this->accountScopedRequest('GET', $endpoint, $accountId, $this->withoutAccountId($query));
    }

    /**
     * @return array<string, mixed>
     */
    public function getCalendarEvent(string $accountId, string $calendarId, string $eventId): array
    {
        $endpoint = sprintf(
            $this->endpoint('calendar_event'),
            rawurlencode($calendarId),
            rawurlencode($eventId),
        );

        return $this->accountScopedRequest('GET', $endpoint, $accountId);
    }

    /**
     * Normalize outbound email payloads to Unipile API v2.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function normalizeEmailSendBody(array $body): array
    {
        // v2: body removed — use html / plain_text.
        if (isset($body['body']) && ! isset($body['html']) && ! isset($body['plain_text'])) {
            $body['html'] = $body['body'];
            unset($body['body']);
        }

        foreach (['to', 'cc', 'bcc'] as $field) {
            if (isset($body[$field])) {
                $body[$field] = $this->normalizeEmailAttendees($body[$field]);
            }
        }

        if (isset($body['from'])) {
            $normalizedFrom = $this->normalizeEmailAttendees([$body['from']]);
            $body['from'] = $normalizedFrom[0] ?? $body['from'];
        }

        // v2: reply_to is Reply-To header attendees; conversation reply uses reply_to_provider_id.
        $threadReplyId = trim((string) (
            $body['reply_to_provider_id']
            ?? $body['reply_to_message_id']
            ?? ''
        ));

        if ($threadReplyId === '' && isset($body['reply_to']) && is_string($body['reply_to'])) {
            $threadReplyId = trim($body['reply_to']);
            unset($body['reply_to']);
        }

        if ($threadReplyId !== '') {
            $body['reply_to_provider_id'] = $threadReplyId;
            unset($body['reply_to_message_id']);
        }

        if (isset($body['reply_to'])) {
            $body['reply_to'] = $this->normalizeEmailAttendees($body['reply_to']);
            if ($body['reply_to'] === []) {
                unset($body['reply_to']);
            }
        }

        return $body;
    }

    /**
     * @param  mixed  $attendees
     * @return list<array{email: string, display_name?: string}>
     */
    private function normalizeEmailAttendees(mixed $attendees): array
    {
        if (! is_array($attendees)) {
            $attendees = [$attendees];
        }

        $normalized = [];
        foreach ($attendees as $attendee) {
            if (is_string($attendee)) {
                $email = trim($attendee);
                if ($email !== '') {
                    $normalized[] = ['email' => $email];
                }
                continue;
            }

            if (! is_array($attendee)) {
                continue;
            }

            $email = trim((string) (
                $attendee['email']
                ?? $attendee['identifier']
                ?? $attendee['address']
                ?? ''
            ));
            if ($email === '') {
                continue;
            }

            $row = ['email' => $email];
            $displayName = trim((string) ($attendee['display_name'] ?? $attendee['name'] ?? ''));
            if ($displayName !== '') {
                $row['display_name'] = $displayName;
            }

            $normalized[] = $row;
        }

        return $normalized;
    }

    /**
     * v2 renamed start/end.time_zone → timezone.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function normalizeCalendarEventBody(array $body): array
    {
        $body = $this->withoutAccountId($body);

        if (isset($body['name']) && ! isset($body['title'])) {
            $body['title'] = $body['name'];
            unset($body['name']);
        }

        foreach (['start', 'end'] as $edge) {
            if (! is_array($body[$edge] ?? null)) {
                continue;
            }
            if (isset($body[$edge]['time_zone']) && ! isset($body[$edge]['timezone'])) {
                $body[$edge]['timezone'] = $body[$edge]['time_zone'];
                unset($body[$edge]['time_zone']);
            }
        }

        // v2: notify is all|externalOnly|none (boolean true was a v1 leftover).
        if (array_key_exists('notify', $body)) {
            $notify = $body['notify'];
            if (is_bool($notify)) {
                $body['notify'] = $notify ? 'all' : 'none';
            } elseif (is_string($notify)) {
                $normalizedNotify = trim($notify);
                $body['notify'] = in_array($normalizedNotify, ['all', 'externalOnly', 'none'], true)
                    ? $normalizedNotify
                    : 'none';
            } else {
                unset($body['notify']);
            }
        }

        if (isset($body['attendees']) && is_array($body['attendees'])) {
            $body['attendees'] = $this->normalizeEmailAttendees($body['attendees']);
        }

        return $body;
    }
}

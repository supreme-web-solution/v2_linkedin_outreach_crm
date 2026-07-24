<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Models\V2IntegrationAccount;
use App\V2\Integrations\ProviderManager;
use App\V2\Integrations\Unipile\UnipileException;
use App\V2\Services\LinkedInConnectionService;
use Illuminate\Support\Arr;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class IntegrationAccountController extends Controller
{
    public function __construct(
        private readonly ProviderManager $providerManager,
        private readonly LinkedInConnectionService $linkedin,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');
        $verify = $request->boolean('verify');

        if ($verify) {
            $data = $this->linkedin->verifyUserAccounts($user);

            return response()->json([
                'data' => $data,
                'meta' => [
                    'organization_id' => $organizationId,
                    'verified' => true,
                ],
            ]);
        }

        $accounts = V2IntegrationAccount::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (V2IntegrationAccount $account) => $this->linkedin->serializeAccount($account));

        return response()->json([
            'data' => $accounts,
            'meta' => [
                'organization_id' => $organizationId,
            ],
        ]);
    }

    public function verify(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');
        $data = $this->linkedin->verifyUserAccounts($user);

        return response()->json([
            'data' => $data,
            'meta' => [
                'organization_id' => $organizationId,
                'verified' => true,
            ],
        ]);
    }

    public function hostedAuthLink(Request $request): JsonResponse
    {
        $organizationId = (int) $request->attributes->get('v2OrganizationId');
        $data = $request->validate([
            'provider' => ['nullable', 'string', 'max:50'],
            'redirect_url' => ['nullable', 'url'],
            'state' => ['nullable', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:120'],
        ]);

        $providerKey = $this->providerManager->defaultProvider();
        $context = $this->buildHostedAuthContext($request, $organizationId, $data);

        try {
            $result = $this->providerManager->account($providerKey)->createHostedAuthLink($context);
        } catch (UnipileException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'hint' => $e->context['hint'] ?? null,
                'error_code' => $e->context['error_code'] ?? 'unipile_error',
            ], $e->statusCode >= 400 && $e->statusCode < 600 ? $e->statusCode : 502);
        }

        return response()->json(['data' => $result]);
    }

    public function unipileStatus(): JsonResponse
    {
        $providerKey = $this->providerManager->defaultProvider();
        $accountProvider = $this->providerManager->account($providerKey);

        if (! method_exists($accountProvider, 'probeConnectivity')) {
            return response()->json([
                'ok' => false,
                'message' => 'Connectivity probe is not available for the configured provider.',
            ], 501);
        }

        $status = $accountProvider->probeConnectivity();

        return response()->json([
            'data' => $status + [
                'base_url' => config('services.unipile.base_url'),
                'mock' => (bool) config('services.unipile.mock', false),
            ],
        ], $status['ok'] ? 200 : 503);
    }

    /**
     * Connect LinkedIn via the li_at cookie (Custom Auth).
     * The extension reads the li_at + user_agent from the browser and sends them here.
     * We forward them to Unipile's POST /accounts endpoint to register the account.
     */
    public function connectViaCookie(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');
        $data = $request->validate([
            'li_at'      => ['required', 'string', 'max:2048'],
            'user_agent' => ['nullable', 'string', 'max:512'],
        ]);

        $providerKey = $this->providerManager->defaultProvider();
        $baseUrl = config('services.unipile.base_url');
        Log::info('[Connect] Cookie connection attempt', [
            'user_id'  => $user->id,
            'base_url' => $baseUrl,
            'has_key'  => !empty(config('services.unipile.api_key')),
        ]);

        try {
            $account = $this->linkedin->connectOrReconnectViaCookie(
                $user,
                $data['li_at'],
                $data['user_agent'] ?? request()->userAgent() ?? '',
                $organizationId,
            );
            Log::info('[Connect] Cookie connection success', ['account_id' => $account->id]);
        } catch (\Throwable $e) {
            Log::error('[Connect] Cookie connection failed', ['error' => $e->getMessage()]);
            $hint = $e instanceof UnipileException
                ? ($e->context['hint'] ?? null)
                : 'Check: (1) UNIPILE_BASE_URL is set in .env to your Unipile DSN URL, (2) UNIPILE_API_KEY is correct, (3) Your li_at cookie is fresh.';

            return response()->json([
                'message' => $e->getMessage(),
                'hint'    => $hint,
                'error_code' => $e instanceof UnipileException ? ($e->context['error_code'] ?? null) : null,
            ], 422);
        }

        return response()->json([
            'data' => $account,
            'connected' => true,
            'unipile_account_id' => $account->getUnipileAccountId(),
        ]);
    }

    /**
     * Connect LinkedIn via credentials (email + password).
     */
    public function connectViaCredentials(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');
        $data = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $providerKey = $this->providerManager->defaultProvider();
        $baseUrl = config('services.unipile.base_url');
        Log::info('[Connect] Credentials connection attempt', [
            'user_id'  => $user->id,
            'email'    => $data['email'],
            'base_url' => $baseUrl,
            'has_key'  => !empty(config('services.unipile.api_key')),
        ]);

        try {
            $result = $this->providerManager->account($providerKey)->connectWithCredentials(
                $data['email'],
                $data['password'],
            );
            Log::info('[Connect] Credentials connection success', ['result' => $result]);
        } catch (\Throwable $e) {
            Log::error('[Connect] Credentials connection failed', ['error' => $e->getMessage()]);
            $message = $e->getMessage();
            $needsHostedAuth = str_contains($message, 'errors/no_client_session')
                || str_contains($message, 'provider/unknown_authentication_context');

            if ($needsHostedAuth) {
                try {
                    $hostedContext = $this->buildHostedAuthContext($request, $organizationId, [
                        'provider' => 'linkedin',
                        'name' => $data['email'],
                        'state' => 'cred_fallback_'.(string) $user->id.'_'.time(),
                    ]);
                    $hosted = $this->providerManager->account($providerKey)->createHostedAuthLink($hostedContext);
                    $authUrl = Arr::get($hosted, 'url')
                        ?? Arr::get($hosted, 'link')
                        ?? Arr::get($hosted, 'auth_url')
                        ?? Arr::get($hosted, 'hosted_auth_url');

                    return response()->json([
                        'message' => 'Direct credentials login needs an active Unipile client session. Continue with secure redirect auth.',
                        'hint' => 'Open auth_redirect_url and complete LinkedIn login there. After success, come back and click Re-detect Session.',
                        'error_code' => 'requires_hosted_auth',
                        'auth_redirect_url' => $authUrl,
                    ], 409);
                } catch (\Throwable $hostedError) {
                    Log::error('[Connect] Hosted auth fallback failed', ['error' => $hostedError->getMessage()]);
                    return response()->json([
                        'message' => $message,
                        'hint' => 'Unipile credentials flow now requires hosted auth for this account, but hosted auth link creation failed. Check UNIPILE_BASE_URL/API key and retry.',
                    ], 422);
                }
            }

            return response()->json([
                'message' => $message,
                'hint'    => 'Check: (1) UNIPILE_BASE_URL is set in .env to your Unipile DSN URL (from Unipile Dashboard → Settings → API), (2) UNIPILE_API_KEY is correct.',
            ], 422);
        }

        $unipileAccountId = $result['account_id'] ?? $result['id'] ?? null;

        $account = V2IntegrationAccount::query()->updateOrCreate(
            [
                'user_id'  => $user->id,
                'provider' => 'linkedin',
                'provider_account_id' => $unipileAccountId ?? ('cred_'.substr(md5($data['email']), 0, 10)),
            ],
            [
                'status' => 'active',
                'meta'   => [
                    'organization_id'    => $organizationId,
                    'unipile_account_id' => $unipileAccountId,
                    'connected_at'       => now()->toIso8601String(),
                    'connection_method'  => 'credentials',
                    'email'              => $data['email'],
                ],
                'last_synced_at' => now(),
            ]
        );

        return response()->json(['data' => $account, 'connected' => true, 'unipile_account_id' => $unipileAccountId]);
    }

    /**
     * Disconnect a LinkedIn account (removes from Unipile + our DB).
     */
    public function disconnect(Request $request, int $id): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $account = V2IntegrationAccount::where('user_id', $user->id)->where('id', $id)->firstOrFail();
        $unipileId = $account->meta['unipile_account_id'] ?? null;

        if ($unipileId) {
            try {
                $providerKey = $this->providerManager->defaultProvider();
                $this->providerManager->account($providerKey)->disconnectAccount($unipileId);
            } catch (\Throwable $_) {
                // Non-fatal — still remove locally
            }
        }

        $account->update(['status' => 'disconnected']);
        return response()->json(['disconnected' => true]);
    }

    /**
     * @deprecated Use connectViaCookie instead.
     * Kept for backward compat with older extension versions.
     */
    public function syncSession(Request $request): JsonResponse
    {
        return $this->connectViaCookie($request);
    }

    public function sync(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');
        $data = $request->validate([
            'provider_account_id' => ['required', 'string', 'max:191'],
            'provider_identity_id' => ['nullable', 'string', 'max:191'],
            'provider' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'max:50'],
            'meta' => ['nullable', 'array'],
        ]);

        $account = V2IntegrationAccount::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'provider' => $data['provider'] ?? 'linkedin',
                'provider_account_id' => $data['provider_account_id'],
            ],
            [
                'provider_identity_id' => $data['provider_identity_id'] ?? null,
                'status' => $data['status'] ?? 'active',
                'meta' => ($data['meta'] ?? []) + ['organization_id' => $organizationId],
                'last_synced_at' => now(),
            ]
        );

        return response()->json(['data' => $account]);
    }

    /**
     * Build hosted-auth context with callback URLs based on current request host (ngrok-safe).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function buildHostedAuthContext(Request $request, int $organizationId, array $data = []): array
    {
        $publicBaseUrl = rtrim($request->getSchemeAndHttpHost(), '/');
        $redirectUrl = !empty($data['redirect_url']) ? (string) $data['redirect_url'] : null;

        return array_merge($data, [
            'organization_id' => $organizationId,
            'success_redirect_url' => $redirectUrl ?: ($publicBaseUrl.'/integrations?connected=1'),
            'failure_redirect_url' => $redirectUrl ?: ($publicBaseUrl.'/integrations?error=1'),
            'notify_url' => $publicBaseUrl.(string) config('services.unipile.webhook_callback_path', '/unipile/callback'),
        ]);
    }
}

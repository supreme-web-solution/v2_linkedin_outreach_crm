<?php



namespace App\V2\Services;



use App\Models\User;

use App\Models\V2IntegrationAccount;

use App\V2\Integrations\ProviderManager;
use App\V2\Integrations\Unipile\UnipileException;

use Illuminate\Http\Request;



class LinkedInConnectionService

{

    public function __construct(private readonly ProviderManager $providerManager)

    {

    }



    public function publicBaseUrl(?Request $request = null): string

    {

        if ($request) {

            return rtrim($request->getSchemeAndHttpHost(), '/');

        }



        return rtrim((string) config('app.url'), '/');

    }



    public function webhookCallbackUrl(?Request $request = null): string

    {

        $path = (string) config('services.unipile.webhook_callback_path', '/unipile/callback');



        return $this->publicBaseUrl($request).(str_starts_with($path, '/') ? $path : '/'.$path);

    }



    /**

     * @return array<string, mixed>

     */

    public function buildHostedAuthContext(

        User $user,

        ?Request $request = null,

        string $successPath = '/integrations?connected=1',

        string $failPath = '/integrations?error=1',

    ): array {

        $base = $this->publicBaseUrl($request);

        $orgId = (int) ($user->current_organization_id ?? 0);



        return [

            'name' => (string) $user->id,

            'state' => 'uid:'.$user->id,

            'provider' => 'LINKEDIN',

            'organization_id' => $orgId,

            'success_redirect_url' => $base.$successPath,

            'failure_redirect_url' => $base.$failPath,

            'notify_url' => $this->webhookCallbackUrl($request),

        ];

    }



    /**

     * @return array<string, mixed>

     */

    public function createHostedAuthLink(

        User $user,

        ?Request $request = null,

        string $successPath = '/integrations?connected=1',

        string $failPath = '/integrations?error=1',

    ): array {

        return $this->providerManager->account(

            $this->providerManager->defaultProvider()

        )->createHostedAuthLink(

            $this->buildHostedAuthContext($user, $request, $successPath, $failPath)

        );

    }



    public function hostedAuthUrl(

        User $user,

        string $successPath,

        string $failPath,

        ?Request $request = null,

    ): ?string {

        try {

            $result = $this->createHostedAuthLink($user, $request, $successPath, $failPath);



            return $result['url'] ?? $result['link'] ?? $result['hosted_url'] ?? null;

        } catch (\Throwable) {

            return null;

        }

    }



    public function isUnipileConfigured(): bool

    {

        return ! empty(config('services.unipile.api_key'))

            && ! empty(config('services.unipile.base_url'));

    }

    public function consolidateProviderAccount(int $userId, string $provider = 'linkedin'): ?V2IntegrationAccount

    {

        $accounts = V2IntegrationAccount::query()

            ->where('user_id', $userId)

            ->where('provider', $provider)

            ->orderByDesc('id')

            ->get();

        if ($accounts->isEmpty()) {

            return null;

        }

        if ($accounts->count() === 1) {

            return $accounts->first();

        }

        $canonical = $accounts->first(

            fn (V2IntegrationAccount $account) => $account->status === 'active' && $account->getUnipileAccountId()

        )

            ?? $accounts->first(fn (V2IntegrationAccount $account) => $account->status === 'active')

            ?? $accounts->first();

        $duplicateIds = $accounts

            ->where('id', '!=', $canonical->id)

            ->pluck('id');

        if ($duplicateIds->isNotEmpty()) {

            V2IntegrationAccount::query()->whereIn('id', $duplicateIds)->delete();

        }

        return $canonical;

    }



    /**

     * @return array<string, mixed>

     */

    public function serializeAccount(V2IntegrationAccount $account): array

    {

        $unipileId = $account->getUnipileAccountId();

        // Cookie-only / mock rows can be status=active without a Unipile id — treat as not connected.

        $effectivelyConnected = $unipileId && $account->status === 'active';

        return [

            'id' => $account->id,

            'provider' => $account->provider,

            'status' => $effectivelyConnected ? 'active' : 'disconnected',

            'provider_account_id' => $account->provider_account_id,

            'connection_method' => $account->meta['connection_method'] ?? 'unknown',

            'connected_at' => $account->meta['connected_at'] ?? null,

            'email' => $account->meta['email'] ?? null,

            'unipile_account_id' => $unipileId,

            'last_synced_at' => $account->last_synced_at?->diffForHumans(),

            'live_status' => $effectivelyConnected

                ? ($account->meta['live_status'] ?? 'connected')

                : 'disconnected',

            'disconnect_reason' => $unipileId

                ? ($account->meta['disconnect_reason'] ?? null)

                : 'LinkedIn was saved locally but never registered with Unipile. Reconnect after setting UNIPILE_API_KEY.',

        ];

    }



    public function isDisconnectedError(\Throwable $e): bool

    {

        if (! $e instanceof UnipileException) {

            return false;

        }

        $type = $e->context['error_code']

            ?? (is_array($e->context['response'] ?? null) ? ($e->context['response']['type'] ?? null) : null);



        return $e->statusCode === 401 || $type === 'errors/disconnected_account';

    }



    public function markDisconnected(V2IntegrationAccount $account, ?string $reason = null): void

    {

        $account->update([

            'status' => 'disconnected',

            'meta' => array_merge($account->meta ?? [], array_filter([

                'live_status' => 'disconnected',

                'disconnected_at' => now()->toIso8601String(),

                'disconnect_reason' => $reason,

            ])),

        ]);

    }



    /**

     * @return array{live_status: string, status: string, message: string}

     */

    public function verifyAccount(V2IntegrationAccount $account): array

    {

        $unipileId = $account->getUnipileAccountId();

        if (! $unipileId) {

            $this->markDisconnected(

                $account,

                'No Unipile account id on record. Reconnect LinkedIn after configuring UNIPILE_API_KEY / UNIPILE_BASE_URL (and UNIPILE_MOCK=false).'

            );



            return [

                'live_status' => 'disconnected',

                'status' => 'disconnected',

                'message' => 'No Unipile account id on record. LinkedIn is not connected for search/outreach.',

            ];

        }



        $provider = $this->providerManager->account($this->providerManager->defaultProvider());



        try {

            $provider->getAccount($unipileId);

            $account->update([

                'status' => 'active',

                'meta' => array_merge($account->meta ?? [], [

                    'live_status' => 'connected',

                    'disconnected_at' => null,

                    'disconnect_reason' => null,

                ]),

                'last_synced_at' => now(),

            ]);



            return [

                'live_status' => 'connected',

                'status' => 'active',

                'message' => 'LinkedIn is connected.',

            ];

        } catch (UnipileException $e) {

            if ($this->isDisconnectedError($e)) {

                $this->markDisconnected($account, $e->getMessage());



                return [

                    'live_status' => 'disconnected',

                    'status' => 'disconnected',

                    'message' => 'LinkedIn is disconnected. Reconnect with a fresh li_at cookie.',

                ];

            }



            throw $e;

        }

    }



    /**

     * @return array<int, array<string, mixed>>

     */

    public function verifyUserAccounts(User $user): array

    {

        $account = $this->consolidateProviderAccount($user->id, 'linkedin');

        if ($account === null) {

            return [];

        }

        $health = $this->verifyAccount($account);



        return [array_merge($this->serializeAccount($account->fresh()), $health)];

    }



    public function connectViaCookie(User $user, string $liAt, string $userAgent, int $orgId): V2IntegrationAccount

    {

        return $this->connectOrReconnectViaCookie($user, $liAt, $userAgent, $orgId);

    }



    public function connectOrReconnectViaCookie(User $user, string $liAt, string $userAgent, int $orgId): V2IntegrationAccount

    {

        $this->assertUnipileReadyForConnect();



        $provider = $this->providerManager->account($this->providerManager->defaultProvider());

        $existing = $this->consolidateProviderAccount($user->id, 'linkedin');

        $unipileAccountId = $existing?->getUnipileAccountId();

        $result = null;



        if ($unipileAccountId) {

            try {

                $result = $provider->reconnectAccount($unipileAccountId, [

                    'provider' => 'LINKEDIN',

                    'access_token' => $liAt,

                    'user_agent' => $userAgent,

                ]);

            } catch (\Throwable) {

                $result = $provider->connectWithCookie($liAt, $userAgent);

                $unipileAccountId = $result['account_id'] ?? $result['id'] ?? null;

            }

        } else {

            $result = $provider->connectWithCookie($liAt, $userAgent);

            $unipileAccountId = $result['account_id'] ?? $result['id'] ?? null;

        }



        if (is_array($result) && ! empty($result['mock'])) {

            throw new UnipileException(

                'LinkedIn connection is running in Unipile mock mode. Set UNIPILE_MOCK=false and configure UNIPILE_API_KEY / UNIPILE_BASE_URL on the server, then reconnect.',

                503

            );

        }



        $unipileAccountId = is_string($unipileAccountId) && $unipileAccountId !== ''

            ? $unipileAccountId

            : null;



        if (! $unipileAccountId) {

            throw new UnipileException(

                'Unipile did not return a LinkedIn account id. Check UNIPILE_API_KEY and UNIPILE_BASE_URL, then reconnect your LinkedIn session.',

                502,

                ['response' => $result]

            );

        }



        $account = V2IntegrationAccount::query()->updateOrCreate(

            [

                'user_id' => $user->id,

                'provider' => 'linkedin',

            ],

            [

                'provider_account_id' => $unipileAccountId,

                'status' => 'active',

                'meta' => array_merge($existing?->meta ?? [], [

                    'organization_id' => $orgId,

                    'unipile_account_id' => $unipileAccountId,

                    'connected_at' => now()->toIso8601String(),

                    'connection_method' => 'cookie',

                    'live_status' => 'connected',

                    'disconnected_at' => null,

                    'disconnect_reason' => null,

                ]),

                'last_synced_at' => now(),

            ]

        );

        return $this->consolidateProviderAccount($user->id, 'linkedin') ?? $account;

    }



    private function assertUnipileReadyForConnect(): void

    {

        if ((bool) config('services.unipile.mock', false)) {

            throw new UnipileException(

                'UNIPILE_MOCK is enabled on this server. Set UNIPILE_MOCK=false and configure UNIPILE_API_KEY / UNIPILE_BASE_URL before connecting LinkedIn.',

                503

            );

        }



        if (trim((string) config('services.unipile.api_key', '')) === '') {

            throw new UnipileException(

                'UNIPILE_API_KEY is missing on this server. Add it in Forge environment, clear config cache, then reconnect LinkedIn.',

                503

            );

        }

    }



    public function handleUnipileFailure(User $user, \Throwable $e): void

    {

        if (! $this->isDisconnectedError($e)) {

            return;

        }



        $account = V2IntegrationAccount::query()

            ->where('user_id', $user->id)

            ->where('provider', 'linkedin')

            ->where('status', 'active')

            ->latest('id')

            ->first();



        if ($account) {

            $this->markDisconnected($account, $e->getMessage());

        }

    }



    public function disconnect(User $user, int $accountId): void

    {

        $account = V2IntegrationAccount::where('user_id', $user->id)->where('id', $accountId)->firstOrFail();

        $unipileId = $account->getUnipileAccountId();



        if ($unipileId) {

            try {

                $this->providerManager->account(

                    $this->providerManager->defaultProvider()

                )->disconnectAccount($unipileId);

            } catch (\Throwable) {

                // Still mark disconnected locally

            }

        }



        $account->update(['status' => 'disconnected']);

    }

}



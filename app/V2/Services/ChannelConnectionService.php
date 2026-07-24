<?php

namespace App\V2\Services;

use App\Models\User;
use App\Models\V2IntegrationAccount;
use App\V2\Integrations\ProviderManager;
use App\V2\Outreach\OutreachChannelRegistry;
use Illuminate\Http\Request;

class ChannelConnectionService
{
    public function __construct(private readonly ProviderManager $providerManager)
    {
    }

    public function isUnipileConfigured(): bool
    {
        return ! empty(config('services.unipile.api_key'))
            && ! empty(config('services.unipile.base_url'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function summarizeForUser(User $user): array
    {
        $providers = array_values(array_unique(array_map(
            fn (array $meta) => (string) $meta['integration_provider'],
            OutreachChannelRegistry::channels()
        )));

        $accounts = V2IntegrationAccount::query()
            ->where('user_id', $user->id)
            ->whereIn('provider', $providers)
            ->latest('id')
            ->get()
            ->unique('provider');

        $byProvider = $accounts->keyBy('provider');
        $summary = [];

        foreach (OutreachChannelRegistry::channels() as $channelKey => $meta) {
            $provider = (string) $meta['integration_provider'];
            $account = $byProvider->get($provider);
            $connected = $account !== null && $account->status === 'active';

            $summary[] = [
                'channel' => $channelKey,
                'label' => $meta['label'],
                'provider' => $provider,
                'connected' => $connected,
                'status' => $account?->status ?? 'missing',
                'live_status' => $account?->meta['live_status'] ?? null,
                'email' => $account?->meta['email'] ?? null,
                'account_name' => $account?->meta['account_name'] ?? null,
                'disconnect_reason' => $account?->meta['disconnect_reason'] ?? null,
                'integration_account_id' => $account?->id,
                'reconnect_url' => '/integrations',
            ];
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    public function createHostedAuthLink(
        User $user,
        string $channelKey,
        ?Request $request = null,
        string $successPath = '/integrations?connected=1',
        string $failPath = '/integrations?error=1',
    ): array {
        $channel = OutreachChannelRegistry::channels()[$channelKey] ?? null;
        if ($channel === null) {
            throw new \InvalidArgumentException("Unknown channel: {$channelKey}");
        }

        $hostedProvider = (string) ($channel['unipile_hosted_provider'] ?? $channel['unipile_providers'][0] ?? 'LINKEDIN');
        $base = rtrim($request?->getSchemeAndHttpHost() ?? config('app.url'), '/');
        $orgId = (int) ($user->current_organization_id ?? 0);

        $context = [
            'name' => (string) $user->id,
            'state' => 'uid:'.$user->id.':channel:'.$channelKey,
            'provider' => $hostedProvider,
            'organization_id' => $orgId,
            'success_redirect_url' => $base.$successPath,
            'failure_redirect_url' => $base.$failPath,
            'notify_url' => rtrim((string) config('app.url'), '/').(string) config('services.unipile.webhook_callback_path', '/unipile/callback'),
        ];

        return $this->providerManager->account(
            $this->providerManager->defaultProvider()
        )->createHostedAuthLink($context);
    }

    public function findAccount(int $userId, string $channelKey): ?V2IntegrationAccount
    {
        $channel = OutreachChannelRegistry::channels()[$channelKey] ?? null;
        if ($channel === null) {
            return null;
        }

        return V2IntegrationAccount::query()
            ->where('user_id', $userId)
            ->where('provider', $channel['integration_provider'])
            ->where('status', 'active')
            ->latest('id')
            ->first();
    }

    public function completeHostedConnection(User $user, string $unipileAccountId, ?string $channelKey = null): V2IntegrationAccount
    {
        $accountData = $this->providerManager->account(
            $this->providerManager->defaultProvider()
        )->getAccount($unipileAccountId);

        $unipileType = strtoupper((string) ($accountData['type'] ?? ''));

        if ($channelKey === null || $channelKey === '') {
            $channelKey = OutreachChannelRegistry::channelKeyForUnipileType($unipileType);
        }

        if ($channelKey === null || $channelKey === '') {
            throw new \InvalidArgumentException("Cannot map Unipile account type {$unipileType} to a channel.");
        }

        $payload = array_merge($accountData, [
            'account_id' => $unipileAccountId,
            'id' => $unipileAccountId,
            'type' => $unipileType,
            'email' => $this->extractEmailFromAccountPayload($accountData),
        ]);

        return $this->persistFromUnipilePayload($user->id, $channelKey, $payload);
    }

    public function persistFromUnipilePayload(int $userId, string $channelKey, array $payload): V2IntegrationAccount
    {
        $channel = OutreachChannelRegistry::channels()[$channelKey] ?? null;
        if ($channel === null) {
            throw new \InvalidArgumentException("Unknown channel: {$channelKey}");
        }

        $unipileId = (string) ($payload['account_id'] ?? $payload['id'] ?? '');
        $providerType = strtoupper((string) ($payload['type'] ?? $payload['provider'] ?? ''));
        $email = $payload['email'] ?? $this->extractEmailFromAccountPayload($payload);

        return V2IntegrationAccount::query()->updateOrCreate(
            [
                'user_id' => $userId,
                'provider' => $channel['integration_provider'],
            ],
            [
                'provider_account_id' => $unipileId !== '' ? $unipileId : 'pending',
                'status' => 'active',
                'meta' => [
                    'unipile_account_id' => $unipileId,
                    'unipile_type' => $providerType,
                    'connection_method' => 'hosted',
                    'connected_at' => now()->toIso8601String(),
                    'live_status' => 'connected',
                    'email' => $email,
                    'account_name' => $payload['name'] ?? null,
                    'channel_key' => $channelKey,
                ],
                'last_synced_at' => now(),
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractEmailFromAccountPayload(array $payload): ?string
    {
        $params = $payload['connection_params'] ?? [];
        if (is_array($params)) {
            $mail = $params['mail'] ?? null;
            if (is_array($mail)) {
                foreach (['username', 'email', 'address'] as $key) {
                    if (! empty($mail[$key]) && is_string($mail[$key])) {
                        return $mail[$key];
                    }
                }
            }
            if (! empty($params['email']) && is_string($params['email'])) {
                return $params['email'];
            }
        }

        $name = $payload['name'] ?? null;
        if (is_string($name) && str_contains($name, '@')) {
            return $name;
        }

        return null;
    }

    public function disconnect(User $user, string $channelKey): void
    {
        $channel = OutreachChannelRegistry::channels()[$channelKey] ?? null;
        if ($channel === null || $channelKey === 'linkedin') {
            throw new \InvalidArgumentException("Unknown channel: {$channelKey}");
        }

        $account = V2IntegrationAccount::query()
            ->where('user_id', $user->id)
            ->where('provider', $channel['integration_provider'])
            ->first();

        if ($account === null) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException();
        }

        $unipileId = $account->getUnipileAccountId();

        if ($unipileId) {
            try {
                $this->providerManager->account(
                    $this->providerManager->defaultProvider()
                )->disconnectAccount($unipileId);
            } catch (\Throwable) {
                // Still mark disconnected locally.
            }
        }

        $account->update([
            'status' => 'disconnected',
            'meta' => array_merge(is_array($account->meta) ? $account->meta : [], [
                'live_status' => 'disconnected',
                'disconnected_at' => now()->toIso8601String(),
            ]),
        ]);
    }
}

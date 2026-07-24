<?php

namespace App\V2\Integrations;

use App\V2\Contracts\Providers\AccountProviderInterface;
use App\V2\Contracts\Providers\InvitationProviderInterface;
use App\V2\Contracts\Providers\MessagingProviderInterface;
use App\V2\Contracts\Providers\PostProviderInterface;
use App\V2\Contracts\Providers\ProfileProviderInterface;
use App\V2\Contracts\Providers\SearchProviderInterface;
use App\V2\Contracts\Providers\WebhookProviderInterface;
use InvalidArgumentException;

class ProviderManager
{
    /**
     * @var array<string, mixed>
     */
    private array $providers;

    /**
     * @param array<string, mixed> $providers
     */
    public function __construct(array $providers = [])
    {
        $this->providers = $providers;
    }

    public function defaultProvider(): string
    {
        return (string) config('v2_provider_policy.default_provider', 'unipile');
    }

    /**
     * @return array<int, string>
     */
    public function fallbackProvidersFor(string $operation): array
    {
        $fallbacks = config("v2_provider_policy.fallbacks.{$operation}", []);
        return is_array($fallbacks) ? $fallbacks : [];
    }

    public function isRegistered(string $providerKey): bool
    {
        return array_key_exists($providerKey, $this->providers);
    }

    /**
     * @return array<int, string>
     */
    public function providersForOperation(string $operation): array
    {
        $ordered = array_merge([$this->defaultProvider()], $this->fallbackProvidersFor($operation));

        $unique = [];
        foreach ($ordered as $key) {
            $value = (string) $key;
            if ($value === '' || in_array($value, $unique, true)) {
                continue;
            }
            $unique[] = $value;
        }

        return $unique;
    }

    public function get(string $providerKey, string $contract)
    {
        $provider = $this->providers[$providerKey] ?? null;

        if ($provider === null) {
            throw new InvalidArgumentException("Provider [{$providerKey}] is not registered.");
        }

        if (!($provider instanceof $contract)) {
            throw new InvalidArgumentException("Provider [{$providerKey}] does not implement [{$contract}].");
        }

        return $provider;
    }

    public function account(string $providerKey): AccountProviderInterface
    {
        return $this->get($providerKey, AccountProviderInterface::class);
    }

    public function search(string $providerKey): SearchProviderInterface
    {
        return $this->get($providerKey, SearchProviderInterface::class);
    }

    public function invitation(string $providerKey): InvitationProviderInterface
    {
        return $this->get($providerKey, InvitationProviderInterface::class);
    }

    public function messaging(string $providerKey): MessagingProviderInterface
    {
        return $this->get($providerKey, MessagingProviderInterface::class);
    }

    public function profile(string $providerKey): ProfileProviderInterface
    {
        return $this->get($providerKey, ProfileProviderInterface::class);
    }

    public function post(string $providerKey): PostProviderInterface
    {
        return $this->get($providerKey, PostProviderInterface::class);
    }

    public function webhook(string $providerKey): WebhookProviderInterface
    {
        return $this->get($providerKey, WebhookProviderInterface::class);
    }
}

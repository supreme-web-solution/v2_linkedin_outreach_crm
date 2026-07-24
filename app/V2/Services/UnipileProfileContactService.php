<?php

namespace App\V2\Services;

use App\Models\User;
use App\Models\V2IntegrationAccount;
use App\V2\Integrations\ProviderManager;
use App\V2\Integrations\Unipile\UnipileProvider;

class UnipileProfileContactService
{
    public function __construct(
        private readonly UnipileProfileEmailService $emailService,
    ) {}

    public function fetchEmailForUser(User $user, string $identifier): ?string
    {
        return $this->emailService->fetchEmailForUser($user, $identifier);
    }

    public function fetchPhoneForUser(User $user, string $identifier): ?string
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            throw new \InvalidArgumentException('Profile identifier not found.');
        }

        $accountId = V2IntegrationAccount::activeUnipileAccountId($user->id);
        if (! $accountId) {
            throw new \RuntimeException('Connect LinkedIn via Integrations first.');
        }

        /** @var UnipileProvider $provider */
        $provider = app(ProviderManager::class)->get('unipile', UnipileProvider::class);

        $profile = preg_match('/^(ACo|ADo|ACw|AE)/i', $identifier)
            ? $provider->getProfileByIdentifier($identifier, ['account_id' => $accountId, 'linkedin_sections' => '*'])
            : $provider->getProfileByIdentifier(
                preg_match('/linkedin\.com\/in\/([^\/\?]+)/i', $identifier, $m) ? $m[1] : $identifier,
                ['account_id' => $accountId, 'linkedin_sections' => '*'],
            );

        return $provider->extractProfilePhone($profile);
    }

    /**
     * @return array{verified: bool, provider_id: ?string, phone: string}
     */
    public function verifyWhatsAppForUser(User $user, string $phone): array
    {
        $accountId = V2IntegrationAccount::activeUnipileAccountIdForProvider($user->id, 'whatsapp');
        if (! $accountId) {
            throw new \RuntimeException('Connect WhatsApp via Integrations first.');
        }

        /** @var UnipileProvider $provider */
        $provider = app(ProviderManager::class)->get('unipile', UnipileProvider::class);
        $normalized = $provider->normalizePhone($phone);
        if ($normalized === '') {
            throw new \InvalidArgumentException('Invalid phone number.');
        }

        try {
            $profile = $provider->lookupMessagingUser($normalized, $accountId, quiet: true);
            $providerId = $provider->extractProviderId($profile);

            return [
                'verified' => $providerId !== null,
                'provider_id' => $providerId,
                'phone' => $normalized,
            ];
        } catch (\Throwable) {
            return [
                'verified' => false,
                'provider_id' => null,
                'phone' => $normalized,
            ];
        }
    }

    /**
     * Resolve a public handle/identifier to a Unipile provider id.
     */
    public function resolvePlatformIdentifier(User $user, string $channel, string $identifier): ?string
    {
        $integrationProvider = match ($channel) {
            'instagram' => 'instagram',
            'telegram' => 'telegram',
            'twitter' => 'twitter',
            default => $channel,
        };

        $accountId = V2IntegrationAccount::activeUnipileAccountIdForProvider($user->id, $integrationProvider);
        if (! $accountId) {
            throw new \RuntimeException('Connect '.ucfirst($integrationProvider).' via Integrations first.');
        }

        $identifier = ltrim(trim($identifier), '@');
        if ($identifier === '') {
            return null;
        }

        /** @var UnipileProvider $provider */
        $provider = app(ProviderManager::class)->get('unipile', UnipileProvider::class);

        try {
            $profile = $provider->lookupMessagingUser($identifier, $accountId);

            return $provider->extractProviderId($profile);
        } catch (\Throwable) {
            return null;
        }
    }

    public function resolvePublicIdentifier(?string $publicIdentifier, ?string $profileUrl): ?string
    {
        return $this->emailService->resolvePublicIdentifier($publicIdentifier, $profileUrl);
    }
}

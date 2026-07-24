<?php

namespace App\V2\Services;

use App\Models\User;
use App\Models\V2IntegrationAccount;
use App\V2\Integrations\ProviderManager;
use App\V2\Integrations\Unipile\UnipileProvider;

class UnipileProfileEmailService
{
    public function fetchEmailForUser(User $user, string $identifier): ?string
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
            ? $provider->getProfileByIdentifier($identifier, ['account_id' => $accountId])
            : $provider->getProfileByUrl('https://www.linkedin.com/in/'.$identifier, $accountId);

        return $provider->extractProfileEmail($profile);
    }

    public function resolvePublicIdentifier(?string $publicIdentifier, ?string $profileUrl): ?string
    {
        $publicIdentifier = trim((string) $publicIdentifier);
        if ($publicIdentifier !== '') {
            return $publicIdentifier;
        }

        if ($profileUrl && preg_match('/\/in\/([^\/\?]+)/', $profileUrl, $matches)) {
            return $matches[1];
        }

        return null;
    }
}

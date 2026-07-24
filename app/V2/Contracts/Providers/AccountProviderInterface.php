<?php

namespace App\V2\Contracts\Providers;

interface AccountProviderInterface
{
    public function createHostedAuthLink(array $context = []): array;

    public function listAccounts(string $ownerId): array;

    public function getAccount(string $accountId): array;

    public function reconnectAccount(string $accountId, array $context = []): array;
}

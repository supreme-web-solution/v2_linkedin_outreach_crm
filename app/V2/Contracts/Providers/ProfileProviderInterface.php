<?php

namespace App\V2\Contracts\Providers;

interface ProfileProviderInterface
{
    public function getProfileByIdentifier(string $identifier, array $context = []): array;

    public function listRelations(array $filters = [], array $context = []): array;

    public function listFollowers(array $filters = [], array $context = []): array;
}

<?php

namespace App\V2\Contracts\Providers;

interface SearchProviderInterface
{
    public function searchPeople(array $filters, array $context = []): array;

    public function searchCompanies(array $filters, array $context = []): array;
}

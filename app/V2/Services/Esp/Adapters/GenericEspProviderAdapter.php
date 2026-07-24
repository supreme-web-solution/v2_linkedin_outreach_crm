<?php

namespace App\V2\Services\Esp\Adapters;

use RuntimeException;

class GenericEspProviderAdapter extends BaseEspProviderAdapter
{
    /**
     * @param  array<string, mixed>  $integrationConfig
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function dispatch(array $integrationConfig, array $payload): array
    {
        throw new RuntimeException('This ESP provider is not wired for live subscription yet.');
    }
}

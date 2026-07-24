<?php

namespace App\V2\Services\Esp\Adapters;

interface EspProviderAdapterInterface
{
    /**
     * @param array<string, mixed> $integrationConfig
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function dispatch(array $integrationConfig, array $payload): array;

    /**
     * @param array<string, mixed> $integrationConfig
     * @param array<string, mixed> $headers
     */
    public function verifySignature(array $integrationConfig, array $headers, string $rawBody): bool;
}

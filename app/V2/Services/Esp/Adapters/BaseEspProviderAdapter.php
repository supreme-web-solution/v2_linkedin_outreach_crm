<?php

namespace App\V2\Services\Esp\Adapters;

abstract class BaseEspProviderAdapter implements EspProviderAdapterInterface
{
    /**
     * @param array<string, mixed> $integrationConfig
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function dispatch(array $integrationConfig, array $payload): array
    {
        return [
            'provider_response' => 'queued',
            'provider_payload' => $payload,
            'provider_meta' => [
                'adapter' => static::class,
                'api_base' => (string) ($integrationConfig['api_base'] ?? ''),
                'list_id' => (string) ($integrationConfig['list_id'] ?? ''),
                'audience_id' => (string) ($integrationConfig['audience_id'] ?? ''),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $integrationConfig
     * @param array<string, mixed> $headers
     */
    public function verifySignature(array $integrationConfig, array $headers, string $rawBody): bool
    {
        $secret = (string) ($integrationConfig['webhook_secret'] ?? '');
        if ($secret === '') {
            return true;
        }

        $provided = $this->extractSignature($headers);
        if ($provided === '') {
            return false;
        }

        $computed = hash_hmac('sha256', $rawBody, $secret);
        $normalizedProvided = str_starts_with($provided, 'sha256=')
            ? substr($provided, 7)
            : $provided;

        return hash_equals($computed, $normalizedProvided);
    }

    /**
     * @param array<string, mixed> $headers
     */
    protected function extractSignature(array $headers): string
    {
        return (string) ($headers['x-esp-signature'][0] ?? $headers['X-ESP-Signature'][0] ?? '');
    }
}

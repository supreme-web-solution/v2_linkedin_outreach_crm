<?php

namespace App\V2\Services\Esp\Adapters;

use Illuminate\Http\Client\Response;
use RuntimeException;

trait EspHttpSupport
{
    /**
     * @param  array<string, mixed>  $config
     */
    protected function requireApiKey(array $config, string $providerLabel): string
    {
        $apiKey = trim((string) ($config['api_key'] ?? ''));
        if ($apiKey === '') {
            throw new RuntimeException("{$providerLabel} API key is missing in CRM integrations.");
        }

        return $apiKey;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function requireEmail(array $payload): string
    {
        $email = strtolower(trim((string) ($payload['recipient'] ?? '')));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Lead has no valid email address for ESP subscription.');
        }

        return $email;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function requireAudienceId(array $config, string $label = 'Audience / List ID'): string
    {
        $id = trim((string) ($config['audience_id'] ?? ''));
        if ($id === '') {
            throw new RuntimeException("{$label} is missing in CRM integrations.");
        }

        return $id;
    }

    protected function httpError(Response $response, string $providerLabel): never
    {
        $detail = (string) (
            $response->json('detail')
            ?? $response->json('message')
            ?? $response->json('title')
            ?? $response->json('error')
            ?? $response->body()
        );

        throw new RuntimeException("{$providerLabel} rejected the request: ".trim($detail));
    }

    /**
     * @return array{first_name: string, last_name: string}
     */
    protected function contactNames(array $payload): array
    {
        return [
            'first_name' => trim((string) ($payload['first_name'] ?? '')),
            'last_name' => trim((string) ($payload['last_name'] ?? '')),
        ];
    }
}

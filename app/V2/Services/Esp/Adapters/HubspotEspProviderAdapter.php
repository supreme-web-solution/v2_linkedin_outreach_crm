<?php

namespace App\V2\Services\Esp\Adapters;

class HubspotEspProviderAdapter extends BaseEspProviderAdapter
{
    /**
     * @param array<string, mixed> $integrationConfig
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function dispatch(array $integrationConfig, array $payload): array
    {
        return [
            'provider_response' => 'hubspot_contact_upserted',
            'provider_payload' => [
                'properties' => [
                    'email' => (string) ($payload['recipient'] ?? ''),
                    'lifecyclestage' => (string) ($integrationConfig['default_lifecycle_stage'] ?? 'lead'),
                    'notes_last_contacted' => (string) ($payload['subject'] ?? ''),
                ],
                'associations' => $payload['associations'] ?? [],
            ],
            'provider_meta' => [
                'adapter' => 'hubspot',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $headers
     */
    protected function extractSignature(array $headers): string
    {
        return (string) ($headers['x-hubspot-signature-v3'][0] ?? $headers['X-HubSpot-Signature-v3'][0] ?? parent::extractSignature($headers));
    }
}

<?php

namespace App\V2\Services\Esp\Adapters;

use Illuminate\Support\Facades\Http;

class HubspotEspProviderAdapter extends BaseEspProviderAdapter
{
    use EspHttpSupport;

    /**
     * @param  array<string, mixed>  $integrationConfig
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function dispatch(array $integrationConfig, array $payload): array
    {
        $apiKey = $this->requireApiKey($integrationConfig, 'HubSpot');
        $email = $this->requireEmail($payload);
        $names = $this->contactNames($payload);

        $properties = array_filter([
            'email' => $email,
            'firstname' => $names['first_name'] !== '' ? $names['first_name'] : null,
            'lastname' => $names['last_name'] !== '' ? $names['last_name'] : null,
            'lifecyclestage' => trim((string) ($integrationConfig['default_lifecycle_stage'] ?? 'lead')) ?: 'lead',
        ]);

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->timeout(30)
            ->post('https://api.hubapi.com/crm/v3/objects/contacts', [
                'properties' => $properties,
            ]);

        if ($response->status() === 409) {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout(30)
                ->patch('https://api.hubapi.com/crm/v3/objects/contacts/'.$email.'?idProperty=email', [
                    'properties' => $properties,
                ]);
        }

        if (! $response->successful()) {
            $this->httpError($response, 'HubSpot');
        }

        $json = $response->json() ?? [];

        return [
            'provider_response' => 'subscribed',
            'provider_payload' => ['properties' => $properties],
            'provider_meta' => [
                'adapter' => 'hubspot',
                'contact_id' => $json['id'] ?? null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    protected function extractSignature(array $headers): string
    {
        return (string) ($headers['x-hubspot-signature-v3'][0] ?? $headers['X-HubSpot-Signature-v3'][0] ?? parent::extractSignature($headers));
    }
}

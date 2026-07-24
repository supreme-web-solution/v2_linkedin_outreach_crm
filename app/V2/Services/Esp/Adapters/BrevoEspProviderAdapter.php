<?php

namespace App\V2\Services\Esp\Adapters;

use Illuminate\Support\Facades\Http;

class BrevoEspProviderAdapter extends BaseEspProviderAdapter
{
    use EspHttpSupport;

    /**
     * @param  array<string, mixed>  $integrationConfig
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function dispatch(array $integrationConfig, array $payload): array
    {
        $apiKey = $this->requireApiKey($integrationConfig, 'Brevo');
        $listId = (int) $this->requireAudienceId($integrationConfig, 'Brevo list ID');
        $email = $this->requireEmail($payload);
        $names = $this->contactNames($payload);

        $body = [
            'email' => $email,
            'updateEnabled' => true,
            'listIds' => [$listId],
            'attributes' => array_filter([
                'FIRSTNAME' => $names['first_name'] !== '' ? $names['first_name'] : null,
                'LASTNAME' => $names['last_name'] !== '' ? $names['last_name'] : null,
            ]),
        ];

        $response = Http::withHeaders(['api-key' => $apiKey])
            ->acceptJson()
            ->timeout(30)
            ->post('https://api.brevo.com/v3/contacts', $body);

        if (! $response->successful() && $response->status() !== 204) {
            $this->httpError($response, 'Brevo');
        }

        $json = $response->json() ?? [];

        return [
            'provider_response' => 'subscribed',
            'provider_payload' => $body,
            'provider_meta' => [
                'adapter' => 'brevo',
                'contact_id' => $json['id'] ?? null,
                'list_id' => $listId,
            ],
        ];
    }
}

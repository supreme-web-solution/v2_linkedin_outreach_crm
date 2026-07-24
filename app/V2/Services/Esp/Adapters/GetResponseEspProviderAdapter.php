<?php

namespace App\V2\Services\Esp\Adapters;

use Illuminate\Support\Facades\Http;

class GetResponseEspProviderAdapter extends BaseEspProviderAdapter
{
    use EspHttpSupport;

    /**
     * @param  array<string, mixed>  $integrationConfig
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function dispatch(array $integrationConfig, array $payload): array
    {
        $apiKey = $this->requireApiKey($integrationConfig, 'GetResponse');
        $campaignId = $this->requireAudienceId($integrationConfig, 'GetResponse campaign ID');
        $email = $this->requireEmail($payload);
        $names = $this->contactNames($payload);

        $body = [
            'email' => $email,
            'name' => trim($names['first_name'].' '.$names['last_name']) ?: $email,
            'campaign' => ['campaignId' => $campaignId],
        ];

        $response = Http::withHeaders(['X-Auth-Token' => 'api-key '.$apiKey])
            ->acceptJson()
            ->timeout(30)
            ->post('https://api.getresponse.com/v3/contacts', $body);

        if (! $response->successful()) {
            $this->httpError($response, 'GetResponse');
        }

        $json = $response->json() ?? [];

        return [
            'provider_response' => 'subscribed',
            'provider_payload' => $body,
            'provider_meta' => [
                'adapter' => 'getresponse',
                'contact_id' => $json['contactId'] ?? null,
                'campaign_id' => $campaignId,
            ],
        ];
    }
}

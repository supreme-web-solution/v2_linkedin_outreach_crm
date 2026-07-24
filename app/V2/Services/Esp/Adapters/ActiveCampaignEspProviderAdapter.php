<?php

namespace App\V2\Services\Esp\Adapters;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class ActiveCampaignEspProviderAdapter extends BaseEspProviderAdapter
{
    use EspHttpSupport;

    /**
     * @param  array<string, mixed>  $integrationConfig
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function dispatch(array $integrationConfig, array $payload): array
    {
        $apiKey = $this->requireApiKey($integrationConfig, 'ActiveCampaign');
        $listId = $this->requireAudienceId($integrationConfig, 'ActiveCampaign list ID');
        $email = $this->requireEmail($payload);
        $names = $this->contactNames($payload);
        $apiBase = rtrim(trim((string) ($integrationConfig['api_base'] ?? '')), '/');

        if ($apiBase === '') {
            throw new RuntimeException('ActiveCampaign API URL is missing (e.g. https://youraccount.api-us1.com).');
        }

        $body = [
            'contact' => array_filter([
                'email' => $email,
                'firstName' => $names['first_name'] !== '' ? $names['first_name'] : null,
                'lastName' => $names['last_name'] !== '' ? $names['last_name'] : null,
            ]),
        ];

        $response = Http::withHeaders(['Api-Token' => $apiKey])
            ->acceptJson()
            ->timeout(30)
            ->post("{$apiBase}/api/3/contact/sync", $body);

        if (! $response->successful()) {
            $this->httpError($response, 'ActiveCampaign');
        }

        $contactId = (string) ($response->json('contact.id') ?? '');
        if ($contactId === '') {
            throw new RuntimeException('ActiveCampaign did not return a contact ID.');
        }

        $listResponse = Http::withHeaders(['Api-Token' => $apiKey])
            ->acceptJson()
            ->timeout(30)
            ->post("{$apiBase}/api/3/contactLists", [
                'contactList' => [
                    'list' => $listId,
                    'contact' => $contactId,
                    'status' => 1,
                ],
            ]);

        if (! $listResponse->successful()) {
            $this->httpError($listResponse, 'ActiveCampaign');
        }

        return [
            'provider_response' => 'subscribed',
            'provider_payload' => $body,
            'provider_meta' => [
                'adapter' => 'activecampaign',
                'contact_id' => $contactId,
                'list_id' => $listId,
            ],
        ];
    }
}

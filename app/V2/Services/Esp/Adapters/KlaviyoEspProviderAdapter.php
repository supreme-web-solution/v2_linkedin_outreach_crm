<?php

namespace App\V2\Services\Esp\Adapters;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class KlaviyoEspProviderAdapter extends BaseEspProviderAdapter
{
    use EspHttpSupport;

    /**
     * @param  array<string, mixed>  $integrationConfig
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function dispatch(array $integrationConfig, array $payload): array
    {
        $apiKey = $this->requireApiKey($integrationConfig, 'Klaviyo');
        $listId = $this->requireAudienceId($integrationConfig, 'Klaviyo list ID');
        $email = $this->requireEmail($payload);
        $names = $this->contactNames($payload);

        $headers = [
            'Authorization' => 'Klaviyo-API-Key '.$apiKey,
            'revision' => '2024-10-15',
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        $profileResponse = Http::withHeaders($headers)
            ->timeout(30)
            ->post('https://a.klaviyo.com/api/profiles/', [
                'data' => [
                    'type' => 'profile',
                    'attributes' => array_filter([
                        'email' => $email,
                        'first_name' => $names['first_name'] !== '' ? $names['first_name'] : null,
                        'last_name' => $names['last_name'] !== '' ? $names['last_name'] : null,
                    ]),
                ],
            ]);

        $profileId = (string) ($profileResponse->json('data.id') ?? '');
        if ($profileResponse->status() === 409) {
            $profileId = (string) ($profileResponse->json('errors.0.meta.duplicate_profile_id') ?? '');
        } elseif (! $profileResponse->successful()) {
            $this->httpError($profileResponse, 'Klaviyo');
        }

        if ($profileId === '') {
            throw new RuntimeException('Klaviyo did not return a profile ID.');
        }

        $listResponse = Http::withHeaders($headers)
            ->timeout(30)
            ->post("https://a.klaviyo.com/api/lists/{$listId}/relationships/profiles/", [
                'data' => [[
                    'type' => 'profile',
                    'id' => $profileId,
                ]],
            ]);

        if (! $listResponse->successful() && $listResponse->status() !== 204) {
            $this->httpError($listResponse, 'Klaviyo');
        }

        return [
            'provider_response' => 'subscribed',
            'provider_payload' => ['email' => $email, 'list_id' => $listId],
            'provider_meta' => [
                'adapter' => 'klaviyo',
                'profile_id' => $profileId,
                'list_id' => $listId,
            ],
        ];
    }
}

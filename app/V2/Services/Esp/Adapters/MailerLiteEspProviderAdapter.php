<?php

namespace App\V2\Services\Esp\Adapters;

use Illuminate\Support\Facades\Http;

class MailerLiteEspProviderAdapter extends BaseEspProviderAdapter
{
    use EspHttpSupport;

    /**
     * @param  array<string, mixed>  $integrationConfig
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function dispatch(array $integrationConfig, array $payload): array
    {
        $apiKey = $this->requireApiKey($integrationConfig, 'MailerLite');
        $groupId = $this->requireAudienceId($integrationConfig, 'MailerLite group ID');
        $email = $this->requireEmail($payload);
        $names = $this->contactNames($payload);

        $body = [
            'email' => $email,
            'fields' => array_filter([
                'name' => trim($names['first_name'].' '.$names['last_name']) ?: null,
                'last_name' => $names['last_name'] !== '' ? $names['last_name'] : null,
            ]),
            'groups' => [$groupId],
        ];

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->timeout(30)
            ->post('https://connect.mailerlite.com/api/subscribers', $body);

        if (! $response->successful()) {
            $this->httpError($response, 'MailerLite');
        }

        $json = $response->json('data') ?? $response->json() ?? [];

        return [
            'provider_response' => 'subscribed',
            'provider_payload' => $body,
            'provider_meta' => [
                'adapter' => 'mailerlite',
                'subscriber_id' => $json['id'] ?? null,
                'group_id' => $groupId,
            ],
        ];
    }
}

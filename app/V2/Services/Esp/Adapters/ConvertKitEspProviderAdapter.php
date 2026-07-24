<?php

namespace App\V2\Services\Esp\Adapters;

use Illuminate\Support\Facades\Http;

class ConvertKitEspProviderAdapter extends BaseEspProviderAdapter
{
    use EspHttpSupport;

    /**
     * @param  array<string, mixed>  $integrationConfig
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function dispatch(array $integrationConfig, array $payload): array
    {
        $apiKey = $this->requireApiKey($integrationConfig, 'ConvertKit');
        $formId = $this->requireAudienceId($integrationConfig, 'ConvertKit form ID');
        $email = $this->requireEmail($payload);
        $names = $this->contactNames($payload);

        $response = Http::acceptJson()
            ->timeout(30)
            ->post("https://api.convertkit.com/v3/forms/{$formId}/subscribe", array_filter([
                'api_key' => $apiKey,
                'email' => $email,
                'first_name' => $names['first_name'] !== '' ? $names['first_name'] : null,
            ]));

        if (! $response->successful()) {
            $this->httpError($response, 'ConvertKit');
        }

        $json = $response->json() ?? [];

        return [
            'provider_response' => 'subscribed',
            'provider_payload' => ['email' => $email, 'form_id' => $formId],
            'provider_meta' => [
                'adapter' => 'convertkit',
                'subscription_id' => $json['subscription']['id'] ?? null,
                'form_id' => $formId,
            ],
        ];
    }
}

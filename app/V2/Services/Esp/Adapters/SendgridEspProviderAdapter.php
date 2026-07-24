<?php

namespace App\V2\Services\Esp\Adapters;

use Illuminate\Support\Facades\Http;

class SendgridEspProviderAdapter extends BaseEspProviderAdapter
{
    use EspHttpSupport;

    /**
     * @param  array<string, mixed>  $integrationConfig
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function dispatch(array $integrationConfig, array $payload): array
    {
        $apiKey = $this->requireApiKey($integrationConfig, 'SendGrid');
        $listId = $this->requireAudienceId($integrationConfig, 'SendGrid list ID');
        $email = $this->requireEmail($payload);
        $names = $this->contactNames($payload);

        $body = [
            'list_ids' => [$listId],
            'contacts' => [array_filter([
                'email' => $email,
                'first_name' => $names['first_name'] !== '' ? $names['first_name'] : null,
                'last_name' => $names['last_name'] !== '' ? $names['last_name'] : null,
            ])],
        ];

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->timeout(30)
            ->put('https://api.sendgrid.com/v3/marketing/contacts', $body);

        if (! $response->successful()) {
            $this->httpError($response, 'SendGrid');
        }

        $json = $response->json() ?? [];

        return [
            'provider_response' => 'subscribed',
            'provider_payload' => $body,
            'provider_meta' => [
                'adapter' => 'sendgrid',
                'job_id' => $json['job_id'] ?? null,
                'list_id' => $listId,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    protected function extractSignature(array $headers): string
    {
        return (string) ($headers['x-twilio-email-event-webhook-signature'][0] ?? $headers['X-Twilio-Email-Event-Webhook-Signature'][0] ?? parent::extractSignature($headers));
    }
}

<?php

namespace App\V2\Services\Esp\Adapters;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class MailchimpEspProviderAdapter extends BaseEspProviderAdapter
{
    /**
     * @param  array<string, mixed>  $integrationConfig
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function dispatch(array $integrationConfig, array $payload): array
    {
        $apiKey = trim((string) ($integrationConfig['api_key'] ?? ''));
        $audienceId = trim((string) ($integrationConfig['audience_id'] ?? ''));
        $email = strtolower(trim((string) ($payload['recipient'] ?? '')));

        if ($apiKey === '') {
            throw new RuntimeException('Mailchimp API key is missing in CRM integrations.');
        }

        if ($audienceId === '') {
            throw new RuntimeException('Mailchimp audience ID is missing in CRM integrations.');
        }

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Lead has no valid email address for Mailchimp.');
        }

        $datacenter = $this->datacenterFromApiKey($apiKey);
        $subscriberHash = md5($email);
        $url = "https://{$datacenter}.api.mailchimp.com/3.0/lists/{$audienceId}/members/{$subscriberHash}";

        $body = [
            'email_address' => $email,
            'status_if_new' => 'subscribed',
            'merge_fields' => array_filter([
                'FNAME' => trim((string) ($payload['first_name'] ?? '')),
                'LNAME' => trim((string) ($payload['last_name'] ?? '')),
            ], fn ($v) => $v !== ''),
        ];

        $response = Http::withBasicAuth('anystring', $apiKey)
            ->acceptJson()
            ->timeout(30)
            ->put($url, $body);

        if (! $response->successful()) {
            $detail = (string) ($response->json('detail') ?? $response->json('title') ?? $response->body());
            throw new RuntimeException('Mailchimp rejected the request: '.$detail);
        }

        $json = $response->json() ?? [];

        return [
            'provider_response' => (string) ($json['status'] ?? 'subscribed'),
            'provider_payload' => $body,
            'provider_meta' => [
                'adapter' => 'mailchimp',
                'audience_id' => $audienceId,
                'member_id' => $json['id'] ?? null,
                'web_id' => $json['web_id'] ?? null,
                'unique_email_id' => $json['unique_email_id'] ?? null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    protected function extractSignature(array $headers): string
    {
        return (string) ($headers['x-mailchimp-signature'][0] ?? $headers['X-Mailchimp-Signature'][0] ?? parent::extractSignature($headers));
    }

    private function datacenterFromApiKey(string $apiKey): string
    {
        $parts = explode('-', $apiKey);
        $dc = strtolower(trim((string) end($parts)));

        if ($dc === '' || strlen($dc) > 10) {
            throw new RuntimeException('Mailchimp API key is invalid — it must include a datacenter suffix (e.g. key-us21).');
        }

        return $dc;
    }
}

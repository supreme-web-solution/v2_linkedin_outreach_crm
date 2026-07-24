<?php

namespace App\V2\Services\Esp\Adapters;

class MailchimpEspProviderAdapter extends BaseEspProviderAdapter
{
    /**
     * @param array<string, mixed> $integrationConfig
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function dispatch(array $integrationConfig, array $payload): array
    {
        return [
            'provider_response' => 'mailchimp_member_upserted',
            'provider_payload' => [
                'audience_id' => (string) ($integrationConfig['audience_id'] ?? ''),
                'email_address' => (string) ($payload['recipient'] ?? ''),
                'merge_fields' => [
                    'FNAME' => (string) ($payload['first_name'] ?? ''),
                    'LNAME' => (string) ($payload['last_name'] ?? ''),
                ],
                'tags' => $payload['tags'] ?? [],
            ],
            'provider_meta' => [
                'adapter' => 'mailchimp',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $headers
     */
    protected function extractSignature(array $headers): string
    {
        return (string) ($headers['x-mailchimp-signature'][0] ?? $headers['X-Mailchimp-Signature'][0] ?? parent::extractSignature($headers));
    }
}

<?php

namespace App\V2\Services\Esp\Adapters;

class SendgridEspProviderAdapter extends BaseEspProviderAdapter
{
    /**
     * @param array<string, mixed> $integrationConfig
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function dispatch(array $integrationConfig, array $payload): array
    {
        return [
            'provider_response' => 'sendgrid_mail_send_queued',
            'provider_payload' => [
                'personalizations' => [[
                    'to' => [['email' => (string) ($payload['recipient'] ?? '')]],
                    'subject' => (string) ($payload['subject'] ?? ''),
                ]],
                'from' => [
                    'email' => (string) ($integrationConfig['from_email'] ?? 'noreply@example.com'),
                    'name' => (string) ($integrationConfig['from_name'] ?? 'LinkedEmpire'),
                ],
                'content' => [[
                    'type' => 'text/plain',
                    'value' => (string) ($payload['body'] ?? ''),
                ]],
            ],
            'provider_meta' => [
                'adapter' => 'sendgrid',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $headers
     */
    protected function extractSignature(array $headers): string
    {
        return (string) ($headers['x-twilio-email-event-webhook-signature'][0] ?? $headers['X-Twilio-Email-Event-Webhook-Signature'][0] ?? parent::extractSignature($headers));
    }
}

<?php

namespace App\V2\Outreach;

use App\V2\Campaign\CampaignSequenceResolver;

class OutreachSequenceResolver extends CampaignSequenceResolver
{
    /**
     * @param  array<string, mixed>  $node
     */
    public function stepType(array $node): string
    {
        $type = (string) ($node['type'] ?? 'action');

        if ($type === 'action') {
            return (string) ($node['action'] ?? 'action');
        }

        return parent::stepType($node);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    public function channel(array $node): ?string
    {
        $channel = trim((string) ($node['channel'] ?? ''));

        return $channel !== '' ? $channel : null;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array{subject: string, body: string}
     */
    public function emailContent(array $node, ?string $firstName = null): array
    {
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $subject = trim((string) ($config['subject'] ?? ''));
        $body = trim((string) ($config['body'] ?? $config['message'] ?? ''));

        if ($firstName !== null && $firstName !== '') {
            $subject = str_replace(['{{firstName}}', '{{first_name}}'], $firstName, $subject);
            $body = str_replace(['{{firstName}}', '{{first_name}}'], $firstName, $body);
        }

        return ['subject' => $subject, 'body' => $body];
    }

    /**
     * @param  array<string, mixed>  $node
     */
    public function delaySeconds(array $node): int
    {
        $value = (int) ($node['value'] ?? 1);
        if ($value <= 0) {
            return 0;
        }

        return parent::delaySeconds($node);
    }
}

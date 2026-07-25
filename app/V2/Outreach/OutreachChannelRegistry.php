<?php

namespace App\V2\Outreach;

class OutreachChannelRegistry
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function channels(): array
    {
        return [
            'linkedin' => [
                'label' => 'LinkedIn',
                'unipile_hosted_provider' => 'LINKEDIN',
                'unipile_providers' => ['LINKEDIN'],
                'integration_provider' => 'linkedin',
                'color' => '#2563eb',
            ],
            'email' => [
                'label' => 'Email',
                'unipile_hosted_provider' => '*:MAILING',
                'unipile_providers' => ['GOOGLE_OAUTH', 'OUTLOOK', 'MAIL', 'ICLOUD'],
                'integration_provider' => 'email',
                'color' => '#059669',
            ],
            'whatsapp' => [
                'label' => 'WhatsApp',
                'unipile_hosted_provider' => 'WHATSAPP',
                'unipile_providers' => ['WHATSAPP'],
                'integration_provider' => 'whatsapp',
                'color' => '#25D366',
            ],
            'instagram' => [
                'label' => 'Instagram',
                'unipile_hosted_provider' => 'INSTAGRAM',
                'unipile_providers' => ['INSTAGRAM'],
                'integration_provider' => 'instagram',
                'color' => '#E1306C',
            ],
            'telegram' => [
                'label' => 'Telegram',
                'unipile_hosted_provider' => 'TELEGRAM',
                'unipile_providers' => ['TELEGRAM'],
                'integration_provider' => 'telegram',
                'color' => '#0088cc',
            ],
            'twitter' => [
                'label' => 'X (Twitter)',
                'unipile_hosted_provider' => 'TWITTER',
                'unipile_providers' => ['TWITTER'],
                'integration_provider' => 'twitter',
                'color' => '#0f1419',
            ],
            'google_calendar' => [
                'label' => 'Google Calendar',
                'unipile_hosted_provider' => 'GOOGLE',
                'unipile_providers' => ['GOOGLE_OAUTH', 'GOOGLE'],
                'integration_provider' => 'google_calendar',
                'color' => '#4285F4',
            ],
            'outlook_calendar' => [
                'label' => 'Outlook Calendar',
                'unipile_hosted_provider' => 'OUTLOOK',
                'unipile_providers' => ['OUTLOOK', 'MICROSOFT'],
                'integration_provider' => 'outlook_calendar',
                'color' => '#0078D4',
            ],
        ];
    }

    /**
     * @return array<string, array<int, array<string, string>>>
     */
    public static function actionsByChannel(): array
    {
        return [
            'linkedin' => [
                ['key' => 'visit_profile', 'label' => 'Visit Profile'],
                ['key' => 'send_invite', 'label' => 'Send Invite'],
                ['key' => 'send_message', 'label' => 'Send Message'],
                ['key' => 'like_post', 'label' => 'Like Post'],
                ['key' => 'endorse', 'label' => 'Endorse Skills'],
            ],
            'email' => [
                ['key' => 'send_email', 'label' => 'Send Email'],
            ],
            'whatsapp' => [
                ['key' => 'send_message', 'label' => 'Send Message'],
            ],
            'instagram' => [
                ['key' => 'send_message', 'label' => 'Send DM'],
            ],
            'telegram' => [
                ['key' => 'send_message', 'label' => 'Send Message'],
            ],
            'twitter' => [
                ['key' => 'send_message', 'label' => 'Send DM'],
                ['key' => 'follow', 'label' => 'Follow'],
            ],
        ];
    }

    /**
     * @return array<string, array<int, array<string, string>>>
     */
    public static function conditionsByChannel(): array
    {
        return [
            'linkedin' => [
                ['key' => 'invite_accepted', 'label' => 'Invite accepted'],
                ['key' => 'has_replied', 'label' => 'Has replied'],
            ],
            'email' => [
                ['key' => 'email_replied', 'label' => 'Email replied'],
                ['key' => 'no_reply', 'label' => 'No reply'],
            ],
            'whatsapp' => [
                ['key' => 'message_replied', 'label' => 'Message replied'],
            ],
            'instagram' => [
                ['key' => 'message_replied', 'label' => 'DM replied'],
            ],
            'telegram' => [
                ['key' => 'message_replied', 'label' => 'Message replied'],
            ],
            'twitter' => [
                ['key' => 'message_replied', 'label' => 'DM replied'],
            ],
        ];
    }

    public static function channelLabel(string $channel): string
    {
        return (string) (self::channels()[$channel]['label'] ?? ucfirst($channel));
    }

    public static function channelKeyForUnipileType(string $type): ?string
    {
        $type = strtoupper($type);

        foreach (self::channels() as $key => $meta) {
            $providers = $meta['unipile_providers'] ?? [];
            if (in_array($type, $providers, true)) {
                return $key;
            }
        }

        return null;
    }

    public static function integrationProviderForUnipileType(string $type): ?string
    {
        $key = self::channelKeyForUnipileType($type);

        return $key !== null
            ? (string) (self::channels()[$key]['integration_provider'] ?? '')
            : null;
    }

    /**
     * @return array<int, string>
     */
    public static function requiredChannelsForNodes(array $nodes): array
    {
        $required = [];
        $resolver = new OutreachSequenceResolver();

        foreach ($resolver->flattenNodes($nodes) as $node) {
            if (($node['type'] ?? '') === 'action') {
                $ch = (string) ($node['channel'] ?? '');
                if ($ch !== '') {
                    $required[] = $ch;
                }
            }
        }

        return array_values(array_unique($required));
    }
}

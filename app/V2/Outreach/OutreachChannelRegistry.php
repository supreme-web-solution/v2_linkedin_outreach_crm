<?php

namespace App\V2\Outreach;

class OutreachChannelRegistry
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function allChannels(): array
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

    public static function isEnabled(string $channel): bool
    {
        if (! array_key_exists($channel, self::allChannels())) {
            return false;
        }

        return (bool) (config('outreach_channels.enabled.'.$channel) ?? false);
    }

    /**
     * @return array<int, string>
     */
    public static function enabledChannelKeys(): array
    {
        return array_values(array_filter(
            array_keys(self::allChannels()),
            fn (string $key) => self::isEnabled($key),
        ));
    }

    /**
     * Enabled channels only — use for UI, connect flows, and outreach builder.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function channels(): array
    {
        return array_intersect_key(
            self::allChannels(),
            array_flip(self::enabledChannelKeys()),
        );
    }

    /**
     * @return array<int, string>
     */
    public static function inboxPlatforms(): array
    {
        $inbox = config('outreach_channels.inbox', []);

        if (! is_array($inbox)) {
            return [];
        }

        return array_values(array_filter(
            $inbox,
            fn (string $key) => self::isEnabled($key),
        ));
    }

    /**
     * Channels that can appear in the outreach sequence builder (excludes calendars — those are Call Manager only).
     *
     * @return array<int, string>
     */
    public static function sequenceChannelKeys(): array
    {
        return array_values(array_filter(
            self::enabledChannelKeys(),
            fn (string $key) => ! in_array($key, self::calendarProviders(), true),
        ));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function sequenceChannels(): array
    {
        return array_intersect_key(
            self::allChannels(),
            array_flip(self::sequenceChannelKeys()),
        );
    }

    /**
     * @return array<string, array<int, array<string, string>>>
     */
    public static function sequenceActionsByChannel(): array
    {
        return array_intersect_key(self::allActionsByChannel(), self::sequenceChannels());
    }

    /**
     * @return array<string, array<int, array<string, string>>>
     */
    public static function sequenceConditionsByChannel(): array
    {
        return array_intersect_key(self::allConditionsByChannel(), self::sequenceChannels());
    }

    /**
     * @return array<int, string>
     */
    public static function calendarProviders(): array
    {
        return array_values(array_filter(
            ['google_calendar', 'outlook_calendar'],
            fn (string $key) => self::isEnabled($key),
        ));
    }

    /**
     * @return array<int, string>
     */
    public static function enabledMessagingChannels(): array
    {
        return array_values(array_filter(
            ['whatsapp', 'instagram', 'telegram', 'twitter'],
            fn (string $key) => self::isEnabled($key),
        ));
    }

    /**
     * @return array<int, string>
     */
    public static function enabledSocialHandleChannels(): array
    {
        return array_values(array_intersect(
            self::enabledMessagingChannels(),
            ['instagram', 'telegram', 'twitter'],
        ));
    }

    /**
     * @return array<string, array<int, array<string, string>>>
     */
    public static function actionsByChannel(): array
    {
        return array_intersect_key(self::allActionsByChannel(), self::channels());
    }

    /**
     * @return array<string, array<int, array<string, string>>>
     */
    private static function allActionsByChannel(): array
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
        return array_intersect_key(self::allConditionsByChannel(), self::channels());
    }

    /**
     * @return array<string, array<int, array<string, string>>>
     */
    private static function allConditionsByChannel(): array
    {
        $messageReplied = [
            ['key' => 'message_replied', 'label' => 'Message replied'],
            ['key' => 'no_reply', 'label' => 'No reply'],
        ];

        return [
            'linkedin' => [
                ['key' => 'invite_accepted', 'label' => 'Invite accepted'],
                ['key' => 'has_replied', 'label' => 'Has replied'],
                ['key' => 'no_reply', 'label' => 'No reply'],
            ],
            'email' => [
                ['key' => 'email_replied', 'label' => 'Email replied'],
                ['key' => 'no_reply', 'label' => 'No reply'],
                ['key' => 'email_opened', 'label' => 'Email opened'],
                ['key' => 'email_bounced', 'label' => 'Email bounced'],
            ],
            'whatsapp' => $messageReplied,
            'instagram' => [
                ['key' => 'message_replied', 'label' => 'DM replied'],
                ['key' => 'no_reply', 'label' => 'No reply'],
            ],
            'telegram' => $messageReplied,
            'twitter' => [
                ['key' => 'message_replied', 'label' => 'DM replied'],
                ['key' => 'no_reply', 'label' => 'No reply'],
            ],
        ];
    }

    public static function channelLabel(string $channel): string
    {
        return (string) (self::allChannels()[$channel]['label'] ?? ucfirst($channel));
    }

    public static function channelKeyForUnipileType(string $type): ?string
    {
        $type = strtoupper($type);

        foreach (self::allChannels() as $key => $meta) {
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
            ? (string) (self::allChannels()[$key]['integration_provider'] ?? '')
            : null;
    }

    /**
     * Channels that need contact prep (email, phone verify, handle resolve) for send steps in the sequence.
     *
     * @return array<int, string>
     */
    public static function contactRequiredChannelsForNodes(array $nodes): array
    {
        $required = [];
        $resolver = new OutreachSequenceResolver();

        foreach ($resolver->flattenNodes($nodes) as $node) {
            if (($node['type'] ?? '') !== 'action') {
                continue;
            }

            $action = (string) ($node['action'] ?? '');
            if ($action === '' || $action === 'start') {
                continue;
            }

            if (! in_array($action, ['send_message', 'send_email', 'send_invite'], true)) {
                continue;
            }

            $ch = (string) ($node['channel'] ?? '');
            if ($ch !== '' && self::isEnabled($ch)) {
                $required[] = $ch;
            }
        }

        return array_values(array_unique($required));
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
                if ($ch !== '' && self::isEnabled($ch)) {
                    $required[] = $ch;
                }
            }
        }

        return array_values(array_unique($required));
    }
}

<?php

namespace Tests\Unit\V2\Outreach;

use App\V2\Outreach\OutreachChannelRegistry;
use Tests\TestCase;

class OutreachChannelRegistryTest extends TestCase
{
    public function test_disabled_channels_are_excluded_from_channels_and_inbox(): void
    {
        config([
            'outreach_channels.enabled' => [
                'linkedin' => true,
                'email' => true,
                'whatsapp' => true,
                'instagram' => true,
                'telegram' => false,
                'twitter' => false,
                'google_calendar' => true,
                'outlook_calendar' => false,
            ],
        ]);

        $this->assertFalse(OutreachChannelRegistry::isEnabled('telegram'));
        $this->assertFalse(OutreachChannelRegistry::isEnabled('twitter'));
        $this->assertTrue(OutreachChannelRegistry::isEnabled('linkedin'));

        $this->assertArrayNotHasKey('telegram', OutreachChannelRegistry::channels());
        $this->assertArrayNotHasKey('twitter', OutreachChannelRegistry::channels());
        $this->assertArrayNotHasKey('telegram', OutreachChannelRegistry::actionsByChannel());

        $this->assertSame(
            ['linkedin', 'whatsapp', 'instagram', 'email'],
            OutreachChannelRegistry::inboxPlatforms(),
        );

        $this->assertSame(
            ['whatsapp', 'instagram'],
            OutreachChannelRegistry::enabledMessagingChannels(),
        );

        $this->assertSame(
            ['instagram'],
            OutreachChannelRegistry::enabledSocialHandleChannels(),
        );

        $this->assertSame(
            ['google_calendar'],
            OutreachChannelRegistry::calendarProviders(),
        );

        $this->assertSame(
            ['linkedin', 'email', 'whatsapp', 'instagram'],
            OutreachChannelRegistry::sequenceChannelKeys(),
        );

        $this->assertArrayNotHasKey('google_calendar', OutreachChannelRegistry::sequenceChannels());
        $this->assertArrayNotHasKey('google_calendar', OutreachChannelRegistry::sequenceActionsByChannel());
    }

    public function test_all_channels_remain_available_for_label_lookup(): void
    {
        config([
            'outreach_channels.enabled.telegram' => false,
        ]);

        $this->assertSame('Telegram', OutreachChannelRegistry::channelLabel('telegram'));
        $this->assertArrayHasKey('telegram', OutreachChannelRegistry::allChannels());
    }
}

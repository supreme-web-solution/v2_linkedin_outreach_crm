<?php

namespace Tests\Unit\V2\Outreach;

use App\V2\Outreach\OutreachImportListService;
use Tests\TestCase;

class OutreachImportListServiceTest extends TestCase
{
    public function test_templates_omit_twitter_when_channel_disabled(): void
    {
        config([
            'outreach_channels.enabled' => [
                'linkedin' => true,
                'email' => true,
                'whatsapp' => true,
                'instagram' => true,
                'telegram' => true,
                'twitter' => false,
                'google_calendar' => true,
                'outlook_calendar' => true,
            ],
        ]);

        $service = app(OutreachImportListService::class);
        $headers = $service->templateHeaders();
        $csv = $service->csvTemplate();

        $this->assertSame(
            ['full_name', 'email', 'phone', 'linkedin_url', 'instagram', 'telegram'],
            $headers,
        );
        $this->assertStringNotContainsString('twitter', $csv);
        $this->assertStringContainsString('full_name,email,phone,linkedin_url,instagram,telegram', $csv);
    }

    public function test_templates_include_twitter_when_channel_enabled(): void
    {
        config([
            'outreach_channels.enabled.twitter' => true,
            'outreach_channels.enabled.linkedin' => true,
            'outreach_channels.enabled.email' => true,
            'outreach_channels.enabled.whatsapp' => true,
            'outreach_channels.enabled.instagram' => true,
            'outreach_channels.enabled.telegram' => true,
        ]);

        $headers = app(OutreachImportListService::class)->templateHeaders();

        $this->assertContains('twitter', $headers);
    }
}

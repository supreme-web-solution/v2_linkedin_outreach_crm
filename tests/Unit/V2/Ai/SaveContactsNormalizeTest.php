<?php

namespace Tests\Unit\V2\Ai;

use App\V2\Ai\Services\PlanSequenceNodeBuilder;
use App\V2\Outreach\OutreachImportListService;
use PHPUnit\Framework\TestCase;

class SaveContactsNormalizeTest extends TestCase
{
    public function test_normalizes_phone_email_and_handles(): void
    {
        $svc = app(OutreachImportListService::class);

        $phone = $svc->normalizeContactMap(['phone' => '+234 903 680 2727', 'full_name' => 'Test User']);
        $this->assertNotNull($phone);
        $this->assertSame('Test User', $phone['full_name']);
        $this->assertNotSame('', $phone['phone']);

        $ig = $svc->normalizeContactMap(['identifier' => '@creativestudio', 'platform' => 'instagram']);
        $this->assertSame('creativestudio', $ig['instagram']);

        $email = $svc->normalizeContactMap(['identifier' => 'vickenconcept@gmail.com']);
        $this->assertSame('vickenconcept@gmail.com', $email['email']);

        $li = $svc->normalizeContactMap([
            'identifier' => 'https://www.linkedin.com/in/eleazarnzerem',
        ]);
        $this->assertStringContainsString('eleazarnzerem', $li['linkedin_url']);
    }

    public function test_one_shot_whatsapp_prefers_whatsapp_channel(): void
    {
        $resolved = app(PlanSequenceNodeBuilder::class)->resolve([
            'channels' => 'WhatsApp',
            'one_shot' => true,
            'message' => 'Hello, good evening.',
        ]);

        $this->assertSame('whatsapp', $resolved['node_model'][0]['channel']);
        $this->assertSame('send_message', $resolved['node_model'][0]['action']);
        $this->assertSame(['action', 'end'], collect($resolved['node_model'])->pluck('type')->all());
    }

    public function test_one_shot_instagram_is_single_dm(): void
    {
        $resolved = app(PlanSequenceNodeBuilder::class)->resolve([
            'channels' => 'Instagram',
            'goal' => 'Send a one-time greeting on Instagram',
            'message' => 'Hey! Quick hello.',
        ]);

        $this->assertSame('instagram', $resolved['node_model'][0]['channel']);
        $this->assertCount(1, collect($resolved['node_model'])->where('type', 'action'));
    }

    public function test_one_shot_linkedin_plus_whatsapp_prefers_whatsapp_when_one_shot(): void
    {
        $resolved = app(PlanSequenceNodeBuilder::class)->resolve([
            'channels' => 'LinkedIn + WhatsApp',
            'one_shot' => true,
            'message' => 'Hi on WhatsApp',
        ]);

        $this->assertSame('whatsapp', $resolved['node_model'][0]['channel']);
    }
}

<?php

namespace Tests\Unit\V2\Ai;

use App\V2\Ai\Services\AiChannelPolicyService;
use App\V2\Ai\Services\CampaignDraftFromPlanService;
use Tests\TestCase;

class CampaignDraftFromPlanServiceTest extends TestCase
{
    public function test_resolves_instagram_only_template(): void
    {
        $type = app(CampaignDraftFromPlanService::class)->resolveTemplateType([
            'preferred_channels' => 'Instagram DM outreach',
        ]);

        $this->assertSame('instagram_only', $type);
    }

    public function test_resolves_social_dm_for_linkedin_and_instagram(): void
    {
        $type = app(CampaignDraftFromPlanService::class)->resolveTemplateType([
            'channels' => 'LinkedIn + Instagram',
        ]);

        $this->assertSame('social_dm', $type);
    }

    public function test_resolves_telegram_only_template(): void
    {
        $type = app(CampaignDraftFromPlanService::class)->resolveTemplateType([
            'preferred_channels' => 'Telegram outreach campaign',
        ]);

        $this->assertSame('telegram_only', $type);
    }

    public function test_resolves_linkedin_telegram_template(): void
    {
        $type = app(CampaignDraftFromPlanService::class)->resolveTemplateType([
            'channels' => 'LinkedIn + Telegram',
        ]);

        $this->assertSame('linkedin_telegram', $type);
    }

    public function test_resolves_linkedin_whatsapp_when_no_email(): void
    {
        $type = app(CampaignDraftFromPlanService::class)->resolveTemplateType([
            'channels' => 'LinkedIn + WhatsApp',
        ]);

        $this->assertSame('linkedin_whatsapp', $type);
    }

    public function test_channel_policy_detects_telegram_in_free_text(): void
    {
        $mentioned = app(AiChannelPolicyService::class)->mentionedInText('run a telegram outreach campaign');

        $this->assertContains('telegram', $mentioned);
    }

    public function test_channel_policy_detects_instagram_in_free_text(): void
    {
        $mentioned = app(AiChannelPolicyService::class)->mentionedInText('instagram dm campaign');

        $this->assertContains('instagram', $mentioned);
    }
}

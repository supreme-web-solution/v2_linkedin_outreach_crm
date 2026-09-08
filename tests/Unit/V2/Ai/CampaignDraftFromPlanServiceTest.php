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

    public function test_builds_custom_sequence_from_prose_steps(): void
    {
        $resolved = app(\App\V2\Ai\Services\PlanSequenceNodeBuilder::class)->resolve([
            'preferred_channels' => 'LinkedIn + Email',
            'sequence' => [
                'Send Invite',
                'Wait 3 days',
                'Send Email',
                'Wait 5 days',
                'Follow-up',
            ],
        ]);

        $this->assertTrue($resolved['custom']);
        $this->assertSame('custom', $resolved['template_type']);
        $types = array_column($resolved['node_model'], 'type');
        $this->assertContains('action', $types);
        $this->assertContains('delay', $types);
        $this->assertContains('end', $types);
    }

    public function test_falls_back_to_preset_when_sequence_is_vague(): void
    {
        $resolved = app(\App\V2\Ai\Services\PlanSequenceNodeBuilder::class)->resolve([
            'preferred_channels' => 'LinkedIn + Email',
            'sequence' => [
                'Qualify interested prospects',
                'Book meetings',
            ],
        ]);

        $this->assertFalse($resolved['custom']);
        $this->assertSame('linkedin_email', $resolved['template_type']);
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

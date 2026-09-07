<?php

namespace Tests\Unit\V2\Ai;

use App\V2\Ai\Services\OnboardingWizardService;
use ReflectionMethod;
use Tests\TestCase;

class OnboardingWizardServiceTest extends TestCase
{
    public function test_infers_whatsapp_campaign_from_free_text(): void
    {
        $service = app(OnboardingWizardService::class);
        $method = new ReflectionMethod($service, 'inferGoalFromMessage');
        $method->setAccessible(true);

        $result = $method->invoke($service, 'i want to run whatsapp campaign');

        $this->assertNotNull($result);
        $this->assertSame('whatsapp_outreach', $result['key']);
        $this->assertStringContainsString('WhatsApp', $result['reply']);
    }

    public function test_does_not_default_ambiguous_run_message_to_linkedin_goal(): void
    {
        $service = app(OnboardingWizardService::class);
        $method = new ReflectionMethod($service, 'inferGoalFromMessage');
        $method->setAccessible(true);

        $result = $method->invoke($service, 'i want to run');

        $this->assertNull($result);
    }
}

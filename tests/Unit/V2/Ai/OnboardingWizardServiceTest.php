<?php

namespace Tests\Unit\V2\Ai;

use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Ai\Enums\AiAutonomyLevel;
use App\V2\Ai\Services\AiEmployeeSettingsService;
use App\V2\Ai\Services\OnboardingWizardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class OnboardingWizardServiceTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_infers_telegram_campaign_from_free_text(): void
    {
        $service = app(OnboardingWizardService::class);
        $method = new ReflectionMethod($service, 'inferGoalFromMessage');
        $method->setAccessible(true);

        $result = $method->invoke($service, 'run telegram outreach');

        $this->assertNotNull($result);
        $this->assertSame('telegram_outreach', $result['key']);
        $this->assertStringContainsString('Telegram', $result['reply']);
    }

    public function test_does_not_default_ambiguous_run_message_to_linkedin_goal(): void
    {
        $service = app(OnboardingWizardService::class);
        $method = new ReflectionMethod($service, 'inferGoalFromMessage');
        $method->setAccessible(true);

        $result = $method->invoke($service, 'i want to run');

        $this->assertNull($result);
    }

    public function test_select_goal_configures_workspace_for_follow_up(): void
    {
        [$user, $org] = $this->userWithOrg();

        $status = app(OnboardingWizardService::class)->selectGoal($user, $org->id, 'follow_up');

        $settings = app(AiEmployeeSettingsService::class)->for($user, $org->id);

        $this->assertTrue($status['workspace_configured']);
        $this->assertSame(AiAutonomyLevel::Autopilot->value, (int) $settings->autonomy_level);
        $this->assertContains('send_inbox_reply', $settings->allowed_execute_tools ?? []);
        $this->assertContains('move_lead_to_nurture', $settings->allowed_execute_tools ?? []);
    }

    public function test_featured_goal_options_stay_short(): void
    {
        [$user, $org] = $this->userWithOrg();

        $status = app(OnboardingWizardService::class)->status($user, $org->id);
        $keys = collect($status['goal_options'])->pluck('key')->all();

        $this->assertCount(5, $keys);
        $this->assertContains('build_linkedin_audience', $keys);
        $this->assertContains('reactivate_old_leads', $keys);
        $this->assertContains('multichannel_outreach', $keys);
        $this->assertNotContains('whatsapp_outreach', $keys);
        $this->assertNotContains('agency', $keys);
    }

    public function test_infers_reactivate_and_linkedin_audience_from_free_text(): void
    {
        $service = app(OnboardingWizardService::class);
        $method = new ReflectionMethod($service, 'inferGoalFromMessage');
        $method->setAccessible(true);

        $reactivate = $method->invoke($service, 'reactivate cold leads');
        $this->assertSame('reactivate_old_leads', $reactivate['key']);

        $audience = $method->invoke($service, 'build linkedin audience of agencies');
        $this->assertSame('build_linkedin_audience', $audience['key']);
    }

    /**
     * @return array{0:User, 1:V2Organization}
     */
    private function userWithOrg(): array
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Test Org',
            'slug' => 'test-org-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        $user->forceFill(['current_organization_id' => $org->id])->save();
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        return [$user, $org];
    }
}

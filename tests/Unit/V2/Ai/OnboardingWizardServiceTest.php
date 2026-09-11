<?php

namespace Tests\Unit\V2\Ai;

use App\Models\User;
use App\Models\V2IntegrationAccount;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Ai\Enums\AiAutonomyLevel;
use App\V2\Ai\Services\AiEmployeeSettingsService;
use App\V2\Ai\Services\BusinessProfileOnboardingService;
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

        $this->assertCount(4, $keys);
        $this->assertContains('get_customers', $keys);
        $this->assertContains('find_prospects', $keys);
        $this->assertContains('follow_up', $keys);
        $this->assertContains('book_meetings', $keys);
        $this->assertNotContains('build_linkedin_audience', $keys);
        $this->assertNotContains('multichannel_outreach', $keys);
        $this->assertNotContains('whatsapp_outreach', $keys);
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

    public function test_phase_stays_on_connect_until_every_counted_channel_is_connected(): void
    {
        [$user, $org] = $this->userWithOrg();
        $this->connectChannel($user, 'instagram');

        app(OnboardingWizardService::class)->selectGoal($user, $org->id, 'instagram_outreach');

        $status = app(OnboardingWizardService::class)->status($user, $org->id);

        $this->assertSame('connect_outreach', $status['phase']);
        $this->assertFalse($status['connections_complete']);
        $this->assertSame('connect', $status['composer_mode']);
        $this->assertSame(1, $status['required_progress']['connected']);
        $this->assertSame(3, $status['required_progress']['total']);
        $this->assertFalse($status['required_progress']['complete']);
    }

    public function test_one_channel_is_enough_when_the_checklist_count_is_one(): void
    {
        [$user, $org] = $this->userWithOrg();
        $this->connectChannel($user, 'linkedin');

        app(OnboardingWizardService::class)->selectGoal($user, $org->id, 'build_linkedin_audience');
        $settings = app(AiEmployeeSettingsService::class)->for($user, $org->id);
        $meta = is_array($settings->meta) ? $settings->meta : [];
        $meta['onboarding']['channel_overrides'] = [
            'required_channels' => ['linkedin'],
            'optional_channels' => [],
        ];
        $settings->forceFill(['meta' => $meta])->save();

        $status = app(OnboardingWizardService::class)->status($user, $org->id);

        $this->assertSame(1, $status['required_progress']['total']);
        $this->assertTrue($status['required_progress']['complete']);
        $this->assertSame('describe_business', $status['phase']);
        $this->assertSame('business', $status['composer_mode']);
    }

    public function test_phase_moves_to_describe_business_after_outreach_ready(): void
    {
        [$user, $org] = $this->userWithOrg();
        $this->connectLinkedIn($user);
        $this->connectChannel($user, 'email');

        app(OnboardingWizardService::class)->selectGoal($user, $org->id, 'build_linkedin_audience');

        $status = app(OnboardingWizardService::class)->status($user, $org->id);

        $this->assertSame('describe_business', $status['phase']);
        $this->assertTrue($status['connections_complete']);
        $this->assertTrue($status['outreach_ready']);
        $this->assertSame('business', $status['composer_mode']);
        $this->assertFalse($status['can_open_command_center']);
    }

    public function test_phase_ready_after_business_and_conversion_assets(): void
    {
        [$user, $org] = $this->userWithOrg();
        $this->connectLinkedIn($user);
        $this->connectChannel($user, 'email');

        app(OnboardingWizardService::class)->selectGoal($user, $org->id, 'build_linkedin_audience');

        app(BusinessProfileOnboardingService::class)->submitBusinessProfile(
            $user,
            $org->id,
            'We help agencies get clients with outbound.',
        );

        app(BusinessProfileOnboardingService::class)->submitConversionAssets(
            $user,
            $org->id,
            'https://example.com/sales',
            null,
        );

        $status = app(OnboardingWizardService::class)->status($user, $org->id);

        $this->assertSame('ready', $status['phase']);
        $this->assertTrue($status['can_open_command_center']);
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

    private function connectLinkedIn(User $user): void
    {
        $this->connectChannel($user, 'linkedin');
    }

    private function connectChannel(User $user, string $provider): void
    {
        V2IntegrationAccount::query()->create([
            'user_id' => $user->id,
            'provider' => $provider,
            'provider_account_id' => $provider.'_acc_'.uniqid(),
            'status' => 'active',
        ]);
    }
}

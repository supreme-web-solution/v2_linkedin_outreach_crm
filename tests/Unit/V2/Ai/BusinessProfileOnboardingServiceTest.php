<?php

namespace Tests\Unit\V2\Ai;

use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Ai\Services\BusinessProfileOnboardingService;
use App\V2\Ai\Services\WorkspaceContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BusinessProfileOnboardingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_submit_business_profile_from_description_builds_icp(): void
    {
        [$user, $org] = $this->userWithOrg();

        $result = app(BusinessProfileOnboardingService::class)->submitBusinessProfile(
            $user,
            $org->id,
            'We help B2B SaaS founders book more demos through LinkedIn outreach.',
        );

        $this->assertStringContainsString('business', strtolower($result['message']));
        $this->assertNotEmpty($result['icp']);

        $settings = app(WorkspaceContextService::class);
        $profile = $settings->businessProfile(
            app(\App\V2\Ai\Services\AiEmployeeSettingsService::class)->for($user, $org->id),
        );

        $this->assertNotEmpty($profile['summary']);
        $this->assertTrue($settings->businessProfileComplete(
            app(\App\V2\Ai\Services\AiEmployeeSettingsService::class)->for($user, $org->id),
        ));
    }

    public function test_submit_business_profile_scrapes_website_via_jina(): void
    {
        Http::fake([
            'r.jina.ai/*' => Http::response("Title: Acme Agency\n\nWe help marketing agencies get clients.", 200),
        ]);

        [$user, $org] = $this->userWithOrg();

        app(BusinessProfileOnboardingService::class)->submitBusinessProfile(
            $user,
            $org->id,
            null,
            'https://example.com',
        );

        $settings = app(\App\V2\Ai\Services\AiEmployeeSettingsService::class)->for($user, $org->id);
        $profile = app(WorkspaceContextService::class)->businessProfile($settings);

        $this->assertSame('https://example.com', $profile['website_url']);
        $this->assertStringContainsString('marketing agencies', strtolower((string) $profile['raw_text']));
    }

    public function test_submit_conversion_assets_requires_at_least_one_url(): void
    {
        [$user, $org] = $this->userWithOrg();

        app(BusinessProfileOnboardingService::class)->submitBusinessProfile(
            $user,
            $org->id,
            'We sell SociFusion to agency owners.',
        );

        $this->expectException(\InvalidArgumentException::class);

        app(BusinessProfileOnboardingService::class)->submitConversionAssets($user, $org->id);
    }

    public function test_submit_conversion_assets_persists_links(): void
    {
        [$user, $org] = $this->userWithOrg();

        app(BusinessProfileOnboardingService::class)->submitBusinessProfile(
            $user,
            $org->id,
            'We sell SociFusion to agency owners.',
        );

        $result = app(BusinessProfileOnboardingService::class)->submitConversionAssets(
            $user,
            $org->id,
            'https://socifusion.com/sales',
            'https://socifusion.com/webinar',
        );

        $this->assertSame('https://socifusion.com/sales', $result['conversion_assets']['sales_page_url']);
        $this->assertSame('https://socifusion.com/webinar', $result['conversion_assets']['webinar_url']);
        $this->assertStringContainsString('sales page', strtolower($result['message']));
        $this->assertStringContainsString('webinar', strtolower($result['message']));

        $settings = app(\App\V2\Ai\Services\AiEmployeeSettingsService::class)->for($user, $org->id);
        $this->assertTrue(app(WorkspaceContextService::class)->conversionAssetsComplete($settings));
    }

    public function test_submit_conversion_assets_sales_only_message(): void
    {
        [$user, $org] = $this->userWithOrg();

        app(BusinessProfileOnboardingService::class)->submitBusinessProfile(
            $user,
            $org->id,
            'We sell SociFusion to agency owners.',
        );

        $result = app(BusinessProfileOnboardingService::class)->submitConversionAssets(
            $user,
            $org->id,
            'https://socifusion.com/sales',
            null,
        );

        $this->assertStringContainsString('sales page', strtolower($result['message']));
        $this->assertStringNotContainsString('webinar', strtolower($result['message']));
    }

    public function test_submit_business_profile_accepts_txt_upload(): void
    {
        [$user, $org] = $this->userWithOrg();

        $file = UploadedFile::fake()->createWithContent(
            'offer.txt',
            'We help coaches fill webinars with qualified leads.',
        );

        app(BusinessProfileOnboardingService::class)->submitBusinessProfile(
            $user,
            $org->id,
            null,
            null,
            $file,
        );

        $settings = app(\App\V2\Ai\Services\AiEmployeeSettingsService::class)->for($user, $org->id);
        $profile = app(WorkspaceContextService::class)->businessProfile($settings);

        $this->assertStringContainsString('webinars', strtolower((string) $profile['raw_text']));
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

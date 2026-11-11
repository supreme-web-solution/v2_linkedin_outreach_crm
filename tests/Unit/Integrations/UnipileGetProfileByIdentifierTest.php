<?php

namespace Tests\Unit\Integrations;

use App\Models\User;
use App\Models\V2IntegrationAccount;
use App\V2\Integrations\Unipile\UnipileException;
use App\V2\Integrations\Unipile\UnipileProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UnipileGetProfileByIdentifierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.unipile.base_url', 'https://unipile.test/v2');
        Config::set('services.unipile.api_key', 'test-key');
        Config::set('services.unipile.mock', false);
    }

    public function test_get_profile_uses_account_path_and_strips_campaign_context(): void
    {
        Http::fake([
            'unipile.test/v2/En0ys8zQREmwHvrLV8I7SA/users/*' => Http::response([
                'provider_id' => 'ACoAADt-s1EBpDjmzFwFx9B_5hMPPM5mxjZGzn0',
                'network_distance' => 'FIRST_DEGREE',
            ], 200),
        ]);

        $provider = app(UnipileProvider::class);
        $provider->getProfileByIdentifier('ACoAADt-s1EBpDjmzFwFx9B_5hMPPM5mxjZGzn0', [
            'owner_id' => '1',
            'organization_id' => 1,
            'account_id' => 'En0ys8zQREmwHvrLV8I7SA',
            'campaign_id' => 4,
            'campaign_run_id' => 5,
            'campaign_lead_id' => 32,
        ]);

        Http::assertSent(function ($request) {
            $url = $request->url();
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            return str_contains($url, '/En0ys8zQREmwHvrLV8I7SA/users/ACoAADt-s1EBpDjmzFwFx9B_5hMPPM5mxjZGzn0')
                && ! array_key_exists('account_id', $query)
                && ! array_key_exists('owner_id', $query)
                && ! array_key_exists('campaign_id', $query)
                && ! array_key_exists('campaign_lead_id', $query);
        });
    }

    public function test_get_profile_resolves_account_id_from_owner_id(): void
    {
        $user = User::factory()->create();

        V2IntegrationAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_account_id' => 'li_test',
            'status' => 'active',
            'meta' => ['unipile_account_id' => 'acc_from_owner'],
        ]);

        Http::fake([
            'unipile.test/v2/acc_from_owner/users/*' => Http::response([
                'provider_id' => 'ACoAAResolved',
            ], 200),
        ]);

        $provider = app(UnipileProvider::class);
        $provider->getProfileByIdentifier('ACoAAResolved', [
            'owner_id' => (string) $user->id,
            'campaign_id' => 4,
        ]);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/acc_from_owner/users/ACoAAResolved');
        });
    }

    public function test_get_profile_requires_account_id_or_connected_owner(): void
    {
        $this->expectException(UnipileException::class);
        $this->expectExceptionMessage('No connected messaging account');

        $provider = app(UnipileProvider::class);
        $provider->getProfileByIdentifier('ACoAANobody', [
            'owner_id' => '999999',
            'campaign_id' => 1,
        ]);
    }

    public function test_get_profile_maps_v2_description_into_headline(): void
    {
        Http::fake([
            'unipile.test/v2/acc_li/users/*' => Http::response([
                'provider_id' => 'ACoHeadline',
                'full_name' => 'Jane Doe',
                'description' => 'VP Sales at Acme',
                'bio' => 'About Jane',
            ], 200),
        ]);

        $profile = app(UnipileProvider::class)->getProfileByIdentifier('ACoHeadline', [
            'account_id' => 'acc_li',
        ]);

        $this->assertSame('VP Sales at Acme', $profile['headline'] ?? null);
        $this->assertSame('VP Sales at Acme', $profile['description'] ?? null);
        $this->assertSame('About Jane', $profile['bio'] ?? null);
    }
}

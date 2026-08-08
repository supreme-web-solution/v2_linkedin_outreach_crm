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

        Config::set('services.unipile.base_url', 'https://unipile.test/api/v1');
        Config::set('services.unipile.api_key', 'test-key');
        Config::set('services.unipile.mock', false);
    }

    public function test_get_profile_sends_account_id_and_strips_campaign_context(): void
    {
        Http::fake([
            'unipile.test/api/v1/users/*' => Http::response([
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
            if (! str_contains($request->url(), '/users/ACoAADt-s1EBpDjmzFwFx9B_5hMPPM5mxjZGzn0')) {
                return false;
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['account_id'] ?? null) === 'En0ys8zQREmwHvrLV8I7SA'
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
            'unipile.test/api/v1/users/*' => Http::response([
                'provider_id' => 'ACoAAResolved',
            ], 200),
        ]);

        $provider = app(UnipileProvider::class);
        $provider->getProfileByIdentifier('ACoAAResolved', [
            'owner_id' => (string) $user->id,
            'campaign_id' => 4,
        ]);

        Http::assertSent(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_contains($request->url(), '/users/ACoAAResolved')
                && ($query['account_id'] ?? null) === 'acc_from_owner';
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
}

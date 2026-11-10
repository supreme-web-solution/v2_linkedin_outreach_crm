<?php

namespace Tests\Unit\Integrations;

use App\V2\Integrations\Unipile\UnipileProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UnipileHostedAuthV2Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.unipile.base_url', 'https://unipile.test/v2');
        Config::set('services.unipile.api_key', 'test-key');
        Config::set('services.unipile.mock', false);
    }

    public function test_create_hosted_auth_link_uses_v2_payload(): void
    {
        Http::fake([
            'unipile.test/v2/auth/link' => Http::response(['url' => 'https://account.unipile.com/link/xyz'], 201),
        ]);

        $provider = app(UnipileProvider::class);
        $result = $provider->createHostedAuthLink([
            'provider' => 'LINKEDIN',
            'success_redirect_url' => 'https://app.test/integrations?connected=1',
            'notify_url' => 'https://app.test/unipile/callback',
            'name' => '42',
            'state' => 'uid:42',
        ]);

        $this->assertSame('https://account.unipile.com/link/xyz', $result['url']);

        Http::assertSent(function ($request) {
            $payload = $request->data();

            return $request->url() === 'https://unipile.test/v2/auth/link'
                && ($payload['providers'] ?? null) === 'linkedin'
                && ($payload['redirect_uri'] ?? null) === 'https://app.test/integrations?connected=1'
                && ($payload['expires_on'] ?? null) !== null
                && ! array_key_exists('api_url', $payload)
                && ! array_key_exists('type', $payload)
                && ! array_key_exists('success_redirect_url', $payload);
        });
    }

    public function test_create_hosted_auth_reconnect_sends_account_id_without_providers(): void
    {
        Http::fake([
            'unipile.test/v2/auth/link' => Http::response(['url' => 'https://account.unipile.com/link/reconnect'], 201),
        ]);

        $provider = app(UnipileProvider::class);
        $provider->createHostedAuthLink([
            'type' => 'reconnect',
            'account_id' => 'acc_existing',
            'redirect_uri' => 'https://app.test/integrations?connected=1',
        ]);

        Http::assertSent(function ($request) {
            $payload = $request->data();

            return ($payload['account_id'] ?? null) === 'acc_existing'
                && ! array_key_exists('providers', $payload);
        });
    }

    public function test_connect_with_cookie_uses_auth_intent(): void
    {
        Http::fake([
            'unipile.test/v2/auth/intent' => Http::response(['account_id' => 'acc_new'], 201),
        ]);

        $provider = app(UnipileProvider::class);
        $result = $provider->connectWithCookie('li_at_value', 'Mozilla/5.0');

        $this->assertSame('acc_new', $result['account_id']);

        Http::assertSent(function ($request) {
            $payload = $request->data();

            return $request->url() === 'https://unipile.test/v2/auth/intent'
                && ($payload['provider'] ?? null) === 'linkedin'
                && ($payload['access_token'] ?? null) === 'li_at_value';
        });
    }
}

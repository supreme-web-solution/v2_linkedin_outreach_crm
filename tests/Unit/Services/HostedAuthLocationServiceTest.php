<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\V2\Services\HostedAuthLocationService;
use Illuminate\Http\Request;
use Tests\TestCase;

class HostedAuthLocationServiceTest extends TestCase
{
    public function test_timezone_beats_us_default(): void
    {
        $user = new User(['timezone' => 'Africa/Lagos']);
        $service = new HostedAuthLocationService();

        $this->assertSame('NG', $service->inferCountry($user));
    }

    public function test_request_country_used_when_timezone_missing(): void
    {
        $request = Request::create('/', 'GET', [], [], [], [
            'HTTP_CF_IPCOUNTRY' => 'GB',
        ]);

        $this->assertSame('GB', (new HostedAuthLocationService())->inferCountry(null, $request));
    }

    public function test_accept_language_region_is_last_request_hint(): void
    {
        $request = Request::create('/', 'GET', [], [], [], [
            'HTTP_ACCEPT_LANGUAGE' => 'en-NG,en;q=0.9',
        ]);

        $this->assertSame('NG', (new HostedAuthLocationService())->inferCountry(null, $request));
    }

    public function test_hosted_auth_config_lets_user_pick_country(): void
    {
        $config = (new HostedAuthLocationService())->hostedAuthConfig('INSTAGRAM', 'NG');

        $this->assertTrue($config['global']['allow_user_country_override']);
        $this->assertFalse($config['global']['allow_user_proxy_override']);
        $this->assertSame('NG', $config['instagram']['auto_proxy_config']['country']);
    }

    public function test_apply_to_context_sets_country_and_config(): void
    {
        $user = new User(['timezone' => 'Africa/Lagos']);
        $context = (new HostedAuthLocationService())->applyToContext([], $user, null, 'INSTAGRAM');

        $this->assertSame('NG', $context['country']);
        $this->assertSame('NG', $context['config']['instagram']['auto_proxy_config']['country']);
    }
}

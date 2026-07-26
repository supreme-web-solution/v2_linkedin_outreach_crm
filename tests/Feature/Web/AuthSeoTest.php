<?php

namespace Tests\Feature\Web;

use App\Models\User;
use App\Models\V2Call;
use App\Models\V2ContentPost;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthSeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_includes_open_graph_meta(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertSee('property="og:title"', false);
        $response->assertSee('Log in', false);
        $response->assertSee('property="og:description"', false);
        $response->assertSee('property="og:image"', false);
        $response->assertSee('images/seo/auth-og.png', false);
        $response->assertSee('name="twitter:card"', false);
    }

    public function test_register_page_includes_open_graph_meta(): void
    {
        $response = $this->get(route('register'));

        $response->assertOk();
        $response->assertSee('property="og:title"', false);
        $response->assertSee('Create account', false);
        $response->assertSee('property="og:image"', false);
        $response->assertSee('images/seo/auth-og.png', false);
    }
}

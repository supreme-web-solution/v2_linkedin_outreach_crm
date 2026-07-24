<?php

namespace Tests\Feature\V2;

use App\Models\User;
use App\Models\V2EspIntegration;
use App\Models\V2ExtensionToken;
use App\Models\V2Lead;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EspPushTest extends TestCase
{
    use RefreshDatabase;

    public function test_push_leads_subscribes_contact_via_mailchimp_api(): void
    {
        Http::fake([
            'https://us21.api.mailchimp.com/*' => Http::response([
                'id' => 'abc123',
                'status' => 'subscribed',
                'web_id' => 42,
            ], 200),
        ]);

        [$user, $organization, $token] = $this->tenantWithToken();
        $lead = V2Lead::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'public_identifier' => 'jane-doe',
            'full_name' => 'Jane Doe',
            'email' => 'jane@example.com',
        ]);

        V2EspIntegration::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'provider' => 'mailchimp',
            'enabled' => true,
            'config' => [
                'api_key' => 'test-key-us21',
                'audience_id' => 'list123',
            ],
        ]);

        $response = $this->withHeaders($this->authHeaders($token, $organization))
            ->postJson('/api/v2/esp/push-leads', [
                'provider' => 'mailchimp',
                'lead_ids' => [$lead->id],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.sent', 1)
            ->assertJsonPath('data.failed', 0);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/lists/list123/members/')
                && ($request->data()['email_address'] ?? null) === 'jane@example.com';
        });
    }

    public function test_push_leads_fails_when_mailchimp_rejects_credentials(): void
    {
        Http::fake([
            'https://us21.api.mailchimp.com/*' => Http::response([
                'title' => 'API Key Invalid',
                'detail' => 'Your API key may be invalid, or you\'ve attempted to access the wrong datacenter.',
            ], 401),
        ]);

        [$user, $organization, $token] = $this->tenantWithToken();
        $lead = V2Lead::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'public_identifier' => 'jane-doe',
            'full_name' => 'Jane Doe',
            'email' => 'jane@example.com',
        ]);

        V2EspIntegration::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'provider' => 'mailchimp',
            'enabled' => true,
            'config' => [
                'api_key' => 'bad-key-us21',
                'audience_id' => 'list123',
            ],
        ]);

        $response = $this->withHeaders($this->authHeaders($token, $organization))
            ->postJson('/api/v2/esp/push-leads', [
                'provider' => 'mailchimp',
                'lead_ids' => [$lead->id],
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('data.sent', 0)
            ->assertJsonPath('data.failed', 1);
    }

    public function test_unwired_esp_returns_clear_error(): void
    {
        [$user, $organization, $token] = $this->tenantWithToken();
        $lead = V2Lead::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'public_identifier' => 'jane-doe',
            'full_name' => 'Jane Doe',
            'email' => 'jane@example.com',
        ]);

        V2EspIntegration::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'provider' => 'unknown_esp',
            'enabled' => true,
            'config' => ['api_key' => 'placeholder'],
        ]);

        $response = $this->withHeaders($this->authHeaders($token, $organization))
            ->postJson('/api/v2/esp/push-leads', [
                'provider' => 'unknown_esp',
                'lead_ids' => [$lead->id],
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('data.failed', 1);

        $errors = $response->json('data.errors');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('not wired', strtolower((string) $errors[0]));
    }

    /**
     * @return array{0: User, 1: V2Organization, 2: string}
     */
    private function tenantWithToken(): array
    {
        $user = User::factory()->create();
        $plainToken = 'v2ext_esp_test_token';

        $organization = V2Organization::query()->create([
            'name' => 'ESP Org',
            'slug' => 'esp-org-'.$user->id,
            'status' => 'active',
        ]);

        V2OrganizationUser::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'capabilities' => ['*'],
            'status' => 'active',
        ]);

        $user->forceFill(['current_organization_id' => $organization->id])->save();

        V2ExtensionToken::query()->create([
            'user_id' => $user->id,
            'name' => 'test',
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addHour(),
        ]);

        return [$user, $organization, $plainToken];
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(string $token, V2Organization $organization): array
    {
        return [
            'Authorization' => 'Bearer '.$token,
            'X-Organization-Id' => (string) $organization->id,
        ];
    }
}

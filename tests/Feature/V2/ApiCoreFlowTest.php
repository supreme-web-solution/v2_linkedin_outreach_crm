<?php

namespace Tests\Feature\V2;

use App\Models\User;
use App\Models\V2ExtensionToken;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiCoreFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.unipile.mock', true);
    }

    public function test_extension_token_can_be_issued_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'password' => 'Password!12345',
        ]);

        $response = $this->postJson('/api/v2/auth/extension-token', [
            'email' => $user->email,
            'password' => 'Password!12345',
            'device_name' => 'Test Extension',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'token',
                'expires_at',
                'user' => ['id', 'name', 'email'],
                'organization' => ['id', 'name'],
            ]);
    }

    public function test_extension_token_endpoint_rejects_invalid_credentials(): void
    {
        $user = User::factory()->create([
            'password' => 'Password!12345',
        ]);

        $response = $this->postJson('/api/v2/auth/extension-token', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
    }

    public function test_protected_route_requires_valid_extension_token(): void
    {
        $response = $this->getJson('/api/v2/integration-accounts');
        $response->assertStatus(401);
    }

    public function test_outreach_invite_enforces_idempotency_key(): void
    {
        $user = User::factory()->create();
        $plainToken = 'v2ext_test_token';
        $organization = $this->attachTenant($user);

        V2ExtensionToken::query()->create([
            'user_id' => $user->id,
            'name' => 'test',
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addHour(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$plainToken,
            'X-Organization-Id' => (string) $organization->id,
        ])->postJson('/api/v2/outreach/invite', [
            'recipient_id' => 'member_123',
            'message' => 'Hello',
        ]);

        $response->assertStatus(422);
    }

    public function test_outreach_invite_blocks_duplicate_idempotent_request(): void
    {
        $user = User::factory()->create();
        $plainToken = 'v2ext_test_token_dup';
        $organization = $this->attachTenant($user);

        V2ExtensionToken::query()->create([
            'user_id' => $user->id,
            'name' => 'test',
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addHour(),
        ]);

        $headers = [
            'Authorization' => 'Bearer '.$plainToken,
            'X-Organization-Id' => (string) $organization->id,
            'Idempotency-Key' => 'dup-key-001',
        ];

        $first = $this->withHeaders($headers)->postJson('/api/v2/outreach/invite', [
            'recipient_id' => 'member_123',
            'message' => 'Hello',
        ]);
        $first->assertStatus(202);

        $second = $this->withHeaders($headers)->postJson('/api/v2/outreach/invite', [
            'recipient_id' => 'member_123',
            'message' => 'Hello',
        ]);
        $second->assertStatus(409);
    }

    private function attachTenant(User $user): V2Organization
    {
        $organization = V2Organization::query()->create([
            'name' => 'Test Org',
            'slug' => 'test-org-'.$user->id,
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

        return $organization;
    }
}

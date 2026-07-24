<?php

namespace Tests\Feature\V2;

use App\Models\User;
use App\Models\V2ExtensionToken;
use App\Models\V2IntegrationAccount;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutreachEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private string $plainToken = 'v2ext_outreach_test';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.unipile.mock', true);
    }

    public function test_list_relations_requires_auth(): void
    {
        $this->getJson('/api/v2/outreach/relations')->assertStatus(401);
    }

    public function test_list_relations_returns_mock_payload_with_linkedin_account(): void
    {
        [$user, $org] = $this->authenticatedContext();

        $response = $this->authHeaders($user, $org)->getJson('/api/v2/outreach/relations?limit=10');

        $response->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_list_sent_invitations_returns_mock_payload(): void
    {
        [$user, $org] = $this->authenticatedContext();

        $response = $this->authHeaders($user, $org)->getJson('/api/v2/outreach/invitations/sent?limit=5');

        $response->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_list_received_invitations_returns_mock_payload(): void
    {
        [$user, $org] = $this->authenticatedContext();

        $response = $this->authHeaders($user, $org)->getJson('/api/v2/outreach/invitations');

        $response->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_profile_action_queues_via_unipile_mock(): void
    {
        [$user, $org] = $this->authenticatedContext();

        $response = $this->authHeaders($user, $org)->postJson('/api/v2/outreach/profile-action', [
            'profile_id' => 'ACoAATest123',
            'action' => 'view_profile',
        ]);

        $response->assertOk()
            ->assertJsonPath('action', 'view_profile');
    }

    public function test_start_chat_accepts_attendee_ids(): void
    {
        [$user, $org] = $this->authenticatedContext();

        $response = $this->authHeaders($user, $org)
            ->withHeader('Idempotency-Key', 'start-chat-test-1')
            ->postJson('/api/v2/outreach/start-chat', [
                'attendee_ids' => ['ACoAATest123'],
                'text' => 'Hello from test',
            ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.queued', true)
            ->assertJsonPath('data.action', 'start_chat');
    }

    public function test_reject_invitation_endpoint(): void
    {
        [$user, $org] = $this->authenticatedContext();

        $response = $this->authHeaders($user, $org)->postJson('/api/v2/outreach/reject-invite', [
            'invitation_id' => 'inv_test_123',
            'shared_secret' => 'mock_shared_secret',
        ]);

        $response->assertOk()
            ->assertJsonPath('rejected', true);
    }

    public function test_withdraw_invitation_endpoint(): void
    {
        [$user, $org] = $this->authenticatedContext();

        $response = $this->authHeaders($user, $org)->postJson('/api/v2/outreach/withdraw-invite', [
            'invitation_id' => 'inv_sent_123',
        ]);

        $response->assertOk()
            ->assertJsonPath('withdrawn', true);
    }

    public function test_resolve_attendee_returns_provider_id_in_mock_mode(): void
    {
        [$user, $org] = $this->authenticatedContext();

        $response = $this->authHeaders($user, $org)->postJson('/api/v2/outreach/resolve-attendee', [
            'identifier' => 'ACoAATest123',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.provider_id', 'ACoAATest123');
    }

    public function test_send_invite_accepts_recipient_id(): void
    {
        [$user, $org] = $this->authenticatedContext();

        $response = $this->authHeaders($user, $org)
            ->withHeader('Idempotency-Key', 'invite-test-1')
            ->postJson('/api/v2/outreach/invite', [
                'recipient_id' => 'ACoAATest123',
                'message' => 'Hello',
            ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.queued', true)
            ->assertJsonPath('data.action', 'invite');
    }

    /** @return array{0: User, 1: V2Organization} */
    private function authenticatedContext(): array
    {
        $user = User::factory()->create();
        $organization = V2Organization::query()->create([
            'name' => 'Outreach Org',
            'slug' => 'outreach-org-'.$user->id,
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
            'token_hash' => hash('sha256', $this->plainToken),
            'expires_at' => now()->addHour(),
        ]);

        V2IntegrationAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_account_id' => 'li_test',
            'status' => 'active',
            'meta' => ['unipile_account_id' => 'acc_mock_123'],
        ]);

        return [$user, $organization];
    }

    private function authHeaders(User $user, V2Organization $organization)
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->plainToken,
            'X-Organization-Id' => (string) $organization->id,
        ]);
    }
}

<?php

namespace Tests\Feature\V2;

use App\Models\User;
use App\Models\V2ExtensionToken;
use App\Models\V2IntegrationAccount;
use App\Models\V2Lead;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Integrations\Unipile\UnipileProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadSearchTest extends TestCase
{
    use RefreshDatabase;

    private string $plainToken = 'v2ext_lead_search_test';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.unipile.mock', true);
    }

    public function test_filter_search_accepts_keywords_and_audience_name(): void
    {
        [$user, $org] = $this->authenticatedContext();

        $response = $this->authHeaders($user, $org)->postJson('/api/v2/leads/search', [
            'keywords' => 'video editor',
            'limit' => 5,
            'audience_name' => 'filter-audience',
            'persist_results' => true,
        ]);

        $response->assertOk()
            ->assertJsonStructure(['data', 'stored_count', 'audience_name', 'source_external_id']);
    }

    public function test_url_search_accepts_linkedin_search_url(): void
    {
        [$user, $org] = $this->authenticatedContext();

        $response = $this->authHeaders($user, $org)->postJson('/api/v2/leads/search', [
            'linkedin_url' => 'https://www.linkedin.com/search/results/people/?keywords=video%20editor',
            'limit' => 5,
            'audience_name' => 'url-audience',
            'persist_results' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('audience_name', 'url-audience');
    }

    public function test_profile_search_accepts_profile_url_without_regex_error(): void
    {
        [$user, $org] = $this->authenticatedContext();

        $response = $this->authHeaders($user, $org)->postJson('/api/v2/leads/search', [
            'profile_url' => 'https://www.linkedin.com/in/eleazarnzerem/',
            'audience_name' => 'eleazar',
            'persist_results' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('audience_name', 'eleazar')
            ->assertJsonPath('stored_count', 1);
    }

    public function test_get_profile_by_url_extracts_vanity_slug(): void
    {
        $provider = app(UnipileProvider::class);
        $profile = $provider->getProfileByUrl(
            'https://www.linkedin.com/in/eleazarnzerem/',
            'acc_mock_123'
        );

        $this->assertSame('eleazarnzerem', $profile['public_identifier'] ?? null);
        $this->assertNotEmpty($profile['provider_id'] ?? $profile['id'] ?? null);
    }

    public function test_profile_search_persists_lead_with_provider_profile_id(): void
    {
        [$user, $org] = $this->authenticatedContext();

        $this->authHeaders($user, $org)->postJson('/api/v2/leads/search', [
            'profile_url' => 'https://www.linkedin.com/in/test-user-slug/',
            'audience_name' => 'profile-persist',
            'persist_results' => true,
        ])->assertOk();

        $this->assertTrue(
            V2Lead::query()->where('user_id', $user->id)->where('public_identifier', 'test-user-slug')->exists()
        );
    }

    /** @return array{0: User, 1: V2Organization} */
    private function authenticatedContext(): array
    {
        $user = User::factory()->create();
        $organization = V2Organization::query()->create([
            'name' => 'Search Org',
            'slug' => 'search-org-'.$user->id,
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
            'provider_account_id' => 'acc_mock_123',
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

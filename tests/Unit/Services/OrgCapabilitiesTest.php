<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Models\V2ExtensionToken;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Services\EntitlementService;
use App\V2\Services\UserBootstrapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrgCapabilitiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_fe_user_receives_full_capabilities(): void
    {
        config()->set('billing.require_entitlement', true);

        $user = User::factory()->create([
            'entitlements' => ['FE'],
        ]);

        $capabilities = app(EntitlementService::class)->orgCapabilitiesFor($user);

        $this->assertSame(['*'], $capabilities);
    }

    public function test_bootstrap_refreshes_legacy_capabilities_for_existing_workspace(): void
    {
        config()->set('billing.require_entitlement', true);

        $user = User::factory()->create([
            'entitlements' => ['FE'],
        ]);

        $organization = V2Organization::query()->create([
            'name' => 'Legacy Org',
            'slug' => 'legacy-org-'.$user->id,
            'status' => 'active',
        ]);

        V2OrganizationUser::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'capabilities' => ['integrations.write'],
            'status' => 'active',
        ]);

        $user->forceFill(['current_organization_id' => $organization->id])->save();

        app(UserBootstrapService::class)->ensurePersonalOrganization($user->fresh());

        $membership = V2OrganizationUser::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->first();

        $this->assertSame(['*'], $membership?->capabilities);
    }

    public function test_connect_cookie_is_not_blocked_for_fe_owner_with_legacy_capabilities(): void
    {
        config()->set('billing.require_entitlement', true);
        config()->set('services.unipile.mock', true);

        $plainToken = 'v2ext_capability_test';
        $user = User::factory()->create([
            'entitlements' => ['FE'],
        ]);

        $organization = V2Organization::query()->create([
            'name' => 'Cookie Org',
            'slug' => 'cookie-org-'.$user->id,
            'status' => 'active',
        ]);

        V2OrganizationUser::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'capabilities' => ['integrations.write'],
            'status' => 'active',
        ]);

        $user->forceFill(['current_organization_id' => $organization->id])->save();

        V2ExtensionToken::query()->create([
            'user_id' => $user->id,
            'name' => 'test',
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addHour(),
        ]);

        app(UserBootstrapService::class)->ensurePersonalOrganization($user->fresh());

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$plainToken,
            'X-Organization-Id' => (string) $organization->id,
        ])->postJson('/api/v2/integration-accounts/connect-cookie', [
            'li_at' => 'test-li-at-cookie-value',
            'user_agent' => 'Mozilla/5.0 Test',
        ]);

        $this->assertNotSame(403, $response->status(), $response->json('message') ?? 'unexpected 403');
    }
}

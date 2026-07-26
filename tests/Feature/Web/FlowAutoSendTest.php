<?php

namespace Tests\Feature\Web;

use App\Models\User;
use App\Models\V2Call;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowAutoSendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('billing.require_entitlement', false);
    }

    public function test_batch_auto_send_toggle_updates_all_calls_in_flow(): void
    {
        $user = $this->userWithOrg();
        $batchId = 'batch-test-001';

        foreach (['Alice', 'Bob'] as $name) {
            V2Call::query()->create([
                'user_id' => $user->id,
                'organization_id' => $user->current_organization_id,
                'prospect_name' => $name,
                'status' => 'engaged',
                'meta' => [
                    'batch_id' => $batchId,
                    'batch_name' => 'Test Flow',
                    'flow_settings' => ['auto_send_suggestions' => true],
                    'auto_send_suggestions' => true,
                ],
            ]);
        }

        $this->actingAs($user)->put(route('conversations.flow.auto-send', $batchId), [
            'auto_send_suggestions' => false,
        ])->assertRedirect();

        $calls = V2Call::query()->where('organization_id', $user->current_organization_id)->get();
        $this->assertCount(2, $calls);

        foreach ($calls as $call) {
            $meta = is_array($call->meta) ? $call->meta : [];
            $this->assertFalse($meta['flow_settings']['auto_send_suggestions'] ?? true);
            $this->assertArrayNotHasKey('auto_send_suggestions', $meta);
        }
    }

    public function test_aggregate_flow_auto_send_route_is_not_found(): void
    {
        $user = $this->userWithOrg();

        $this->actingAs($user)->put(route('conversations.flow.auto-send', 'all'), [
            'auto_send_suggestions' => false,
        ])->assertNotFound();
    }

    public function test_launch_wizard_can_set_auto_send_off_for_new_flow(): void
    {
        $user = $this->userWithOrg();
        $user->forceFill([
            'call_settings' => array_merge(
                app(\App\V2\Services\CallOrchestrationService::class)->defaultSettings(),
                ['auto_send_suggestions' => true],
            ),
        ])->save();

        $leads = collect([
            ['id' => 1, 'profileid' => 'linkedin-profile-1', 'name' => 'Alice'],
            ['id' => 2, 'profileid' => 'linkedin-profile-2', 'name' => 'Bob'],
        ]);

        app(\App\V2\Services\CallOrchestrationService::class)->createCallsFromLeads(
            $user,
            (int) $user->current_organization_id,
            $leads,
            [
                'batch_name' => 'Wizard flow',
                'run' => false,
                'auto_send_suggestions' => false,
            ],
        );

        $calls = V2Call::query()->where('organization_id', $user->current_organization_id)->get();
        $this->assertCount(2, $calls);

        foreach ($calls as $call) {
            $meta = is_array($call->meta) ? $call->meta : [];
            $this->assertFalse($meta['flow_settings']['auto_send_suggestions'] ?? true);
        }
    }

    private function userWithOrg(): User
    {
        $user = User::factory()->create();
        $organization = V2Organization::query()->create([
            'name' => 'Flow Org',
            'slug' => 'flow-org-'.$user->id,
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

        return $user->fresh();
    }
}

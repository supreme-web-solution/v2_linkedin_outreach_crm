<?php

namespace Tests\Feature\Web;

use App\Models\User;
use App\Models\V2Campaign;
use App\Models\V2CampaignNodeEvent;
use App\Models\V2Organization;
use App\V2\Campaign\CampaignActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_fetch_campaign_activity(): void
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Test Org',
            'slug' => 'test-org',
            'owner_id' => $user->id,
        ]);
        $user->forceFill(['current_organization_id' => $org->id])->save();

        $campaign = V2Campaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'Test Campaign',
            'sequence_type' => 'lead_gen',
            'status' => 'running',
            'node_model' => [],
        ]);

        $logger = new CampaignActivityLogger();
        $logger->log($campaign->id, null, null, null, 'started', 'Campaign started.');

        $response = $this->actingAs($user)->getJson("/campaigns/{$campaign->id}/activity");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'events');

        $this->assertSame(1, V2CampaignNodeEvent::query()->where('campaign_id', $campaign->id)->count());
    }
}

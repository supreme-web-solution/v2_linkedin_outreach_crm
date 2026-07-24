<?php

namespace Tests\Unit\Campaign;

use App\Models\User;
use App\Models\V2Campaign;
use App\Models\V2CampaignLead;
use App\Models\V2CampaignRun;
use App\Models\V2Organization;
use App\V2\Campaign\CampaignCompletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignCompletionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_marks_campaign_completed_when_all_leads_finished(): void
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Test Org',
            'slug' => 'test-org',
            'owner_id' => $user->id,
        ]);

        $campaign = V2Campaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'Test Campaign',
            'sequence_type' => 'lead_gen',
            'status' => 'running',
            'node_model' => [],
        ]);

        V2CampaignLead::query()->create([
            'campaign_id' => $campaign->id,
            'lead_id' => null,
            'full_name' => 'Jane Doe',
            'status' => 'done',
        ]);

        $run = V2CampaignRun::query()->create([
            'user_id' => $user->id,
            'legacy_campaign_id' => $campaign->id,
            'status' => 'running',
            'started_at' => now(),
        ]);

        $service = new CampaignCompletionService();
        $this->assertTrue($service->maybeFinish($campaign->fresh(), $run));

        $this->assertSame('completed', $campaign->fresh()->status);
        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_does_not_complete_while_leads_are_still_running(): void
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Test Org',
            'slug' => 'test-org',
            'owner_id' => $user->id,
        ]);

        $campaign = V2Campaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'Active Campaign',
            'sequence_type' => 'lead_gen',
            'status' => 'running',
            'node_model' => [],
        ]);

        V2CampaignLead::query()->create([
            'campaign_id' => $campaign->id,
            'lead_id' => null,
            'full_name' => 'Jane Doe',
            'status' => 'running',
        ]);

        $service = new CampaignCompletionService();
        $this->assertFalse($service->maybeFinish($campaign->fresh()));

        $this->assertSame('running', $campaign->fresh()->status);
    }
}

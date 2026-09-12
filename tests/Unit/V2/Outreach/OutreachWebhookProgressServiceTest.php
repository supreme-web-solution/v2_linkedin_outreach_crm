<?php

namespace Tests\Unit\V2\Outreach;

use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLead;
use App\Models\V2OutreachLeadProgress;
use App\V2\Outreach\OutreachWebhookProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutreachWebhookProgressServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_reply_update_promotes_done_lead_on_completed_campaign(): void
    {
        [$campaign, $lead] = $this->completedOneShotLead();

        app(OutreachWebhookProgressService::class)->recordInboundReply(
            $lead,
            $campaign,
            'email',
            'Thanks — tell me more.',
        );

        $lead->refresh();
        $progress = V2OutreachLeadProgress::query()
            ->where('outreach_lead_id', $lead->id)
            ->first();

        $this->assertSame('replied', $lead->status);
        $this->assertTrue($progress?->channel_state['email']['replied'] ?? false);
    }

    /**
     * @return array{0: V2OutreachCampaign, 1: V2OutreachLead}
     */
    private function completedOneShotLead(): array
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Webhook Org',
            'slug' => 'webhook-org-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $campaign = V2OutreachCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'Completed one-shot',
            'status' => 'completed',
            'node_model' => [],
        ]);

        $lead = V2OutreachLead::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'full_name' => 'Prospect',
            'email' => 'prospect@example.com',
            'status' => 'done',
        ]);

        V2OutreachLeadProgress::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'outreach_lead_id' => $lead->id,
            'current_node_key' => 1,
            'next_node_key' => 0,
            'run_status' => 0,
            'channel_state' => [],
        ]);

        return [$campaign, $lead];
    }
}

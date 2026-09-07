<?php

namespace Tests\Unit\V2\Ai;

use App\Models\User;
use App\Models\V2Conversation;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLead;
use App\Models\V2OutreachLeadProgress;
use App\V2\Ai\Services\LeadNurtureCommandCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LeadNurtureCommandCenterServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_moves_lead_to_nurture_via_conversation_and_pauses_progress(): void
    {
        [$user, $campaign, $lead, $conversation, $progress] = $this->leadWithConversation();

        $result = app(LeadNurtureCommandCenterService::class)->moveToNurture(
            user: $user,
            conversationId: $conversation->id,
            followUpDays: 90,
            reason: 'Maybe later — Sarah asked to reconnect in Q4.',
        );

        $lead->refresh();
        $progress->refresh();

        $this->assertSame($lead->id, $result['outreach_lead_id']);
        $this->assertStringContainsString('90-day nurture', $result['message']);
        $this->assertSame('nurture', $lead->meta['qualification']['stage'] ?? null);
        $this->assertSame('Maybe later — Sarah asked to reconnect in Q4.', $lead->meta['qualification']['notes'] ?? null);
        $this->assertSame('nurture', $progress->meta['paused_reason'] ?? null);
        $this->assertNotNull($progress->next_run_at);
        $this->assertStringContainsString('/inbox/linkedin/'.$conversation->id, (string) $result['inbox_url']);
    }

    public function test_moves_lead_to_nurture_by_outreach_lead_id(): void
    {
        [$user, $campaign, $lead] = $this->leadWithConversation(skipConversation: true);

        $result = app(LeadNurtureCommandCenterService::class)->moveToNurture(
            user: $user,
            outreachLeadId: $lead->id,
            followUpDays: 45,
            reason: 'Not ready yet',
        );

        $lead->refresh();

        $this->assertSame($lead->id, $result['outreach_lead_id']);
        $this->assertSame('nurture', $lead->meta['qualification']['stage'] ?? null);
        $this->assertSame(45, $lead->meta['qualification']['nurture_follow_up_days'] ?? null);
    }

    public function test_resume_from_nurture_clears_stage_and_unpauses_progress(): void
    {
        Queue::fake();

        [$user, $campaign, $lead, $conversation, $progress] = $this->leadWithConversation();

        app(LeadNurtureCommandCenterService::class)->moveToNurture(
            user: $user,
            conversationId: $conversation->id,
            followUpDays: 90,
        );

        app(LeadNurtureCommandCenterService::class)->resumeFromNurture($user, $lead->id);

        $lead->refresh();
        $progress->refresh();

        $this->assertSame('resumed', $lead->meta['qualification']['stage'] ?? null);
        $this->assertNull($progress->meta['paused_reason'] ?? null);
        $this->assertTrue($progress->next_run_at->lte(now()->addMinute()));
    }

    /**
     * @return array{0:User, 1:V2OutreachCampaign, 2:V2OutreachLead, 3:?V2Conversation, 4:?V2OutreachLeadProgress}
     */
    private function leadWithConversation(bool $skipConversation = false): array
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Test Org',
            'slug' => 'test-org-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        $user->forceFill(['current_organization_id' => $org->id])->save();
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $campaign = V2OutreachCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'Nurture Test',
            'status' => 'running',
            'node_model' => [],
        ]);

        $lead = V2OutreachLead::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'full_name' => 'Sarah Prospect',
            'status' => 'running',
            'meta' => [],
        ]);

        $progress = V2OutreachLeadProgress::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'outreach_lead_id' => $lead->id,
            'current_node_key' => 1,
            'next_node_key' => 2,
            'run_status' => 1,
            'channel_state' => [],
            'next_run_at' => now()->addDay(),
        ]);

        $conversation = null;
        if (! $skipConversation) {
            $conversation = V2Conversation::query()->create([
                'user_id' => $user->id,
                'provider' => 'linkedin',
                'provider_chat_id' => 'li_nurture_1',
                'status' => 'active',
                'meta' => [
                    'source' => 'unified_inbox',
                    'outreach_campaign_id' => $campaign->id,
                    'outreach_lead_id' => $lead->id,
                    'prospect_name' => 'Sarah Prospect',
                ],
            ]);
        }

        return [$user, $campaign, $lead, $conversation, $progress];
    }
}

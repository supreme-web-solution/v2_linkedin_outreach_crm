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
use App\V2\Ai\Services\NurtureQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NurtureQueueServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_nurture_leads_with_counts(): void
    {
        [$user, $lead, $conversation] = $this->seedNurtureLead(followUpDays: 5);

        $result = app(NurtureQueueService::class)->forUser($user);

        $this->assertSame(1, $result['counts']['total']);
        $this->assertSame(1, $result['counts']['due_this_week']);
        $this->assertCount(1, $result['items']);
        $this->assertSame($lead->id, $result['items'][0]['outreach_lead_id']);
        $this->assertSame($conversation->id, $result['items'][0]['conversation_id']);
        $this->assertSame('due_soon', $result['items'][0]['status']);
    }

    public function test_brief_for_user_returns_headline(): void
    {
        [$user] = $this->seedNurtureLead(followUpDays: 120);

        $brief = app(NurtureQueueService::class)->briefForUser($user);

        $this->assertSame(1, $brief['total']);
        $this->assertStringContainsString('1 in nurture', $brief['headline']);
    }

    public function test_resume_removes_lead_from_nurture_queue(): void
    {
        [$user, $lead] = $this->seedNurtureLead(followUpDays: 90);

        app(LeadNurtureCommandCenterService::class)->resumeFromNurture($user, $lead->id);

        $result = app(NurtureQueueService::class)->forUser($user);
        $this->assertSame(0, $result['counts']['total']);
    }

    public function test_due_for_follow_up_returns_overdue_and_due_soon(): void
    {
        [$user, $overdueLead] = $this->seedNurtureLead(followUpDays: 30);
        $meta = is_array($overdueLead->meta) ? $overdueLead->meta : [];
        $qualification = is_array($meta['qualification'] ?? null) ? $meta['qualification'] : [];
        $qualification['nurture_follow_up_at'] = now()->subDays(2)->toIso8601String();
        $meta['qualification'] = $qualification;
        $overdueLead->forceFill(['meta' => $meta])->save();

        $this->seedNurtureLeadForUser($user, followUpDays: 5);

        $due = app(NurtureQueueService::class)->dueForFollowUp($user);

        $this->assertGreaterThanOrEqual(2, $due['counts']['due_total']);
        $this->assertSame("Who's due for nurture follow-up?", $due['alex_starter']);
        $this->assertStringContainsString('due for nurture', $due['summary']);
    }

    /**
     * @return array{0:User, 1:V2OutreachLead, 2:V2Conversation}
     */
    private function seedNurtureLead(int $followUpDays): array
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
            'name' => 'Nurture Queue Test',
            'status' => 'running',
            'node_model' => [],
        ]);

        $lead = V2OutreachLead::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'full_name' => 'Sarah Prospect',
            'status' => 'running',
            'meta' => [],
        ]);

        app(LeadNurtureCommandCenterService::class)->moveToNurture(
            user: $user,
            conversationId: null,
            outreachLeadId: $lead->id,
            followUpDays: $followUpDays,
            reason: 'Maybe later',
        );

        $conversation = V2Conversation::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_chat_id' => 'li_nurture_queue_1',
            'status' => 'active',
            'meta' => [
                'source' => 'unified_inbox',
                'outreach_campaign_id' => $campaign->id,
                'outreach_lead_id' => $lead->id,
                'prospect_name' => 'Sarah Prospect',
            ],
        ]);

        return [$user, $lead->fresh(), $conversation];
    }

    private function seedNurtureLeadForUser(User $user, int $followUpDays): V2OutreachLead
    {
        $campaign = V2OutreachCampaign::query()->where('user_id', $user->id)->firstOrFail();

        $lead = V2OutreachLead::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'full_name' => 'Due Soon Prospect',
            'status' => 'running',
            'meta' => [],
        ]);

        app(LeadNurtureCommandCenterService::class)->moveToNurture(
            user: $user,
            conversationId: null,
            outreachLeadId: $lead->id,
            followUpDays: $followUpDays,
            reason: 'Check back soon',
        );

        return $lead->fresh();
    }
}

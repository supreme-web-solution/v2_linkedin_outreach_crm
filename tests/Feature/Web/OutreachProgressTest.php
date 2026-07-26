<?php

namespace Tests\Feature\Web;

use App\Jobs\V2\ProcessOutreachLeadJob;
use App\Jobs\V2\ProcessUnipileWebhookEventJob;
use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\Models\V2IntegrationAccount;
use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLead;
use App\Models\V2OutreachLeadProgress;
use App\Models\V2OutreachNodeEvent;
use App\Models\V2ProviderEvent;
use App\V2\Outreach\OutreachCampaignStatsService;
use App\V2\Outreach\OutreachConditionEvaluator;
use App\V2\Outreach\OutreachWebhookProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OutreachProgressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('billing.require_entitlement', false);
    }

    public function test_invitation_accepted_marks_linkedin_state_and_dispatches_job(): void
    {
        Queue::fake([ProcessOutreachLeadJob::class]);

        $user = $this->userWithOrg();
        $campaign = $this->campaignWithCondition($user);
        $lead = V2OutreachLead::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'full_name' => 'Alex Lead',
            'provider_profile_id' => 'ACoAAInvite123',
            'status' => 'running',
        ]);

        V2OutreachLeadProgress::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'outreach_lead_id' => $lead->id,
            'current_node_key' => 1,
            'next_node_key' => 3,
            'run_status' => 1,
            'channel_state' => [],
        ]);

        app(OutreachWebhookProgressService::class)->markLinkedInInviteAccepted($lead->fresh());

        $progress = V2OutreachLeadProgress::query()->where('outreach_lead_id', $lead->id)->first();

        $this->assertTrue($progress->acceptance_status);
        $this->assertTrue($progress->channel_state['linkedin']['invite_accepted'] ?? false);
        Queue::assertPushed(ProcessOutreachLeadJob::class);
    }

    public function test_unipile_invitation_webhook_advances_matching_lead(): void
    {
        Queue::fake([ProcessOutreachLeadJob::class]);

        $user = $this->userWithOrg();
        $campaign = $this->campaignWithCondition($user);
        $lead = V2OutreachLead::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'full_name' => 'Webhook Lead',
            'provider_profile_id' => 'ACoAAWebhook456',
            'status' => 'running',
        ]);

        V2OutreachLeadProgress::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'outreach_lead_id' => $lead->id,
            'current_node_key' => 1,
            'next_node_key' => 3,
            'run_status' => 1,
            'channel_state' => [],
        ]);

        $event = V2ProviderEvent::query()->create([
            'user_id' => $user->id,
            'provider' => 'unipile',
            'event_id' => 'evt_invite_accept_1',
            'event_type' => 'invitation.accepted',
            'payload' => [
                'data' => [
                    'profile_id' => 'ACoAAWebhook456',
                ],
            ],
        ]);

        ProcessUnipileWebhookEventJob::dispatchSync($event->id);

        $progress = V2OutreachLeadProgress::query()->where('outreach_lead_id', $lead->id)->first();
        $this->assertTrue($progress->channel_state['linkedin']['invite_accepted'] ?? false);
        Queue::assertPushed(ProcessOutreachLeadJob::class);
    }

    public function test_condition_evaluator_waits_then_resolves_no_reply(): void
    {
        $evaluator = app(OutreachConditionEvaluator::class);

        $progress = new V2OutreachLeadProgress([
            'channel_state' => ['whatsapp' => []],
            'meta' => ['condition_wait_since' => now()->subDays(4)->toIso8601String()],
        ]);

        $node = [
            'type' => 'condition',
            'channel' => 'whatsapp',
            'condition' => 'no_reply',
        ];

        $this->assertTrue($evaluator->evaluate($progress, $node));
    }

    public function test_campaign_stats_service_aggregates_lead_and_event_counts(): void
    {
        $user = $this->userWithOrg();

        $campaign = V2OutreachCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'name' => 'Stats Campaign',
            'status' => 'running',
            'node_model' => [],
        ]);

        foreach (['pending', 'running', 'replied', 'done', 'done'] as $status) {
            V2OutreachLead::query()->create([
                'outreach_campaign_id' => $campaign->id,
                'full_name' => 'Lead '.$status,
                'status' => $status,
            ]);
        }

        $lead = $campaign->outreachLeads()->first();

        V2OutreachNodeEvent::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'outreach_lead_id' => $lead->id,
            'channel' => 'linkedin',
            'status' => 'completed',
            'message' => 'Step completed',
            'executed_at' => now(),
        ]);

        V2OutreachNodeEvent::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'outreach_lead_id' => $lead->id,
            'channel' => 'email',
            'status' => 'failed',
            'message' => 'Step failed',
            'executed_at' => now(),
        ]);

        $stats = app(OutreachCampaignStatsService::class)->statsFor($campaign);

        $this->assertSame(5, $stats['total_leads']);
        $this->assertSame(1, $stats['by_status']['replied']);
        $this->assertSame(2, $stats['by_status']['done']);
        $this->assertSame(60.0, $stats['completion_rate']);
        $this->assertSame(20.0, $stats['reply_rate']);
        $this->assertSame(1, $stats['steps_completed']);
        $this->assertSame(1, $stats['steps_failed']);
        $this->assertSame(1, $stats['actions_by_channel']['linkedin']);
    }

    public function test_duplicate_campaign_creates_draft_copy_without_leads(): void
    {
        $user = $this->userWithOrg();

        $source = V2OutreachCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'name' => 'Original',
            'template_type' => 'linkedin_only',
            'status' => 'running',
            'node_model' => [
                ['key' => 1, 'type' => 'action', 'channel' => 'linkedin', 'action' => 'send_invite', 'label' => 'Invite'],
            ],
            'meta' => ['channel_inbox' => ['linkedin' => ['auto_reply_enabled' => true]]],
        ]);

        V2OutreachLead::query()->create([
            'outreach_campaign_id' => $source->id,
            'full_name' => 'Should not copy',
            'status' => 'running',
        ]);

        $response = $this->actingAs($user)->post(route('outreach.duplicate', $source->id));

        $response->assertRedirect();
        $copy = V2OutreachCampaign::query()
            ->where('name', 'Original (copy)')
            ->where('status', 'draft')
            ->first();

        $this->assertNotNull($copy);
        $this->assertSame('linkedin_only', $copy->template_type);
        $this->assertSame(0, $copy->outreachLeads()->count());
    }

    public function test_save_as_template_is_hidden_from_index_and_shown_on_create(): void
    {
        $user = $this->userWithOrg();

        $campaign = V2OutreachCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'name' => 'Live Campaign',
            'status' => 'draft',
            'node_model' => [
                ['key' => 1, 'type' => 'action', 'channel' => 'email', 'action' => 'send_email', 'label' => 'Email'],
            ],
        ]);

        $this->actingAs($user)->post(route('outreach.save-template', $campaign->id), [
            'name' => 'My Email Flow',
            'description' => 'Two-touch email sequence',
        ])->assertRedirect(route('outreach.create'));

        $template = V2OutreachCampaign::query()
            ->where('name', 'My Email Flow')
            ->where('status', 'template')
            ->first();

        $this->assertNotNull($template);

        $this->actingAs($user)->get(route('outreach'))
            ->assertInertia(fn ($page) => $page
                ->has('campaigns.data', 1)
                ->where('campaigns.data.0.name', 'Live Campaign')
            );

        $this->actingAs($user)->get(route('outreach.create'))
            ->assertInertia(fn ($page) => $page
                ->has('templates.saved_'.$template->id)
                ->where('templates.saved_'.$template->id.'.label', 'My Email Flow')
            );
    }

    public function test_condition_evaluator_respects_custom_timeout_from_node_config(): void
    {
        $evaluator = app(OutreachConditionEvaluator::class);

        $progress = new V2OutreachLeadProgress([
            'channel_state' => ['whatsapp' => []],
            'meta' => ['condition_wait_since' => now()->subDays(2)->toIso8601String()],
        ]);

        $node = [
            'type' => 'condition',
            'channel' => 'whatsapp',
            'condition' => 'no_reply',
            'config' => ['timeout_days' => 1],
        ];

        $this->assertTrue($evaluator->evaluate($progress, $node));
    }

    public function test_condition_evaluator_resolves_email_opened(): void
    {
        $evaluator = app(OutreachConditionEvaluator::class);

        $progress = new V2OutreachLeadProgress([
            'channel_state' => ['email' => ['opened' => true]],
            'meta' => ['condition_wait_since' => now()->subDay()->toIso8601String()],
        ]);

        $node = [
            'type' => 'condition',
            'channel' => 'email',
            'condition' => 'email_opened',
        ];

        $this->assertTrue($evaluator->evaluate($progress, $node));
    }

    public function test_email_open_webhook_updates_channel_state_and_dispatches_job(): void
    {
        Queue::fake([ProcessOutreachLeadJob::class]);

        $user = $this->userWithOrg();
        $campaign = $this->campaignWithEmailCondition($user);
        $lead = V2OutreachLead::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'full_name' => 'Email Lead',
            'email' => 'prospect@example.com',
            'status' => 'running',
        ]);

        V2OutreachLeadProgress::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'outreach_lead_id' => $lead->id,
            'current_node_key' => 1,
            'next_node_key' => 2,
            'run_status' => 1,
            'channel_state' => [],
        ]);

        $event = V2ProviderEvent::query()->create([
            'user_id' => $user->id,
            'provider' => 'unipile',
            'event_id' => 'evt_email_open_1',
            'event_type' => 'email.opened',
            'payload' => [
                'data' => [
                    'to_email' => 'prospect@example.com',
                ],
            ],
        ]);

        ProcessUnipileWebhookEventJob::dispatchSync($event->id);

        $progress = V2OutreachLeadProgress::query()->where('outreach_lead_id', $lead->id)->first();
        $this->assertTrue($progress->channel_state['email']['opened'] ?? false);
        Queue::assertPushed(ProcessOutreachLeadJob::class);
    }

    public function test_account_disconnected_webhook_pauses_running_campaigns(): void
    {
        $user = $this->userWithOrg();

        V2IntegrationAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_account_id' => 'acc_linkedin_1',
            'status' => 'active',
            'meta' => ['organization_id' => $user->current_organization_id],
        ]);

        $campaign = V2OutreachCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'name' => 'Running Campaign',
            'status' => 'running',
            'node_model' => [],
        ]);

        $event = V2ProviderEvent::query()->create([
            'user_id' => $user->id,
            'provider' => 'unipile',
            'event_id' => 'evt_disconnect_1',
            'event_type' => 'account.disconnected',
            'payload' => [
                'data' => [
                    'account_id' => 'acc_linkedin_1',
                    'provider' => 'LINKEDIN',
                ],
            ],
        ]);

        ProcessUnipileWebhookEventJob::dispatchSync($event->id);

        $this->assertSame('paused', $campaign->fresh()->status);
        $this->assertSame('channel_disconnected', $campaign->fresh()->meta['pause_reason'] ?? null);
    }

    public function test_campaign_stats_include_funnel_and_invite_accepted_rate(): void
    {
        $user = $this->userWithOrg();

        $campaign = V2OutreachCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'name' => 'Funnel Campaign',
            'status' => 'running',
            'node_model' => [
                ['key' => 1, 'type' => 'action', 'channel' => 'linkedin', 'action' => 'send_invite', 'label' => 'Invite'],
                ['key' => 2, 'type' => 'action', 'channel' => 'email', 'action' => 'send_email', 'label' => 'Email'],
            ],
        ]);

        $lead = V2OutreachLead::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'full_name' => 'Lead One',
            'status' => 'running',
        ]);

        V2OutreachLeadProgress::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'outreach_lead_id' => $lead->id,
            'acceptance_status' => true,
            'channel_state' => ['linkedin' => ['invite_accepted' => true]],
        ]);

        V2OutreachNodeEvent::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'outreach_lead_id' => $lead->id,
            'node_key' => 1,
            'channel' => 'linkedin',
            'status' => 'completed',
            'message' => 'Invite sent',
            'executed_at' => now(),
        ]);

        $stats = app(OutreachCampaignStatsService::class)->statsFor($campaign);

        $this->assertSame(100.0, $stats['invite_accepted_rate']);
        $this->assertCount(2, $stats['funnel']);
        $this->assertSame(1, $stats['funnel'][0]['reached']);
    }

    public function test_duplicate_template_creates_new_saved_template(): void
    {
        $user = $this->userWithOrg();

        $template = V2OutreachCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'name' => 'Original Template',
            'status' => 'template',
            'node_model' => [
                ['key' => 1, 'type' => 'action', 'channel' => 'email', 'action' => 'send_email', 'label' => 'Email'],
            ],
        ]);

        $this->actingAs($user)->post(route('outreach.duplicate-template', $template->id))
            ->assertRedirect(route('outreach.create'));

        $copy = V2OutreachCampaign::query()
            ->where('name', 'Original Template (copy)')
            ->where('status', 'template')
            ->first();

        $this->assertNotNull($copy);
        $this->assertNotSame($template->id, $copy->id);
    }

    public function test_outreach_show_includes_stats_payload(): void
    {
        $user = $this->userWithOrg();

        $campaign = V2OutreachCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'name' => 'Show Stats',
            'status' => 'draft',
            'node_model' => [],
        ]);

        V2OutreachLead::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'full_name' => 'Lead One',
            'status' => 'pending',
        ]);

        $this->actingAs($user)->get(route('outreach.show', $campaign->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('crm/outreach/OutreachDetail')
                ->where('stats.total_leads', 1)
                ->where('stats.by_status.pending', 1)
                ->has('stats.funnel')
            );
    }

    private function userWithOrg(): User
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

        return $user->fresh();
    }

    private function campaignWithCondition(User $user): V2OutreachCampaign
    {
        return V2OutreachCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'name' => 'Condition Campaign',
            'status' => 'running',
            'node_model' => [
                ['key' => 1, 'type' => 'action', 'channel' => 'linkedin', 'action' => 'send_invite', 'label' => 'Invite'],
                ['key' => 3, 'type' => 'condition', 'channel' => 'linkedin', 'condition' => 'invite_accepted', 'label' => 'Accepted?', 'branches' => [
                    'accepted' => [
                        ['key' => 4, 'type' => 'action', 'channel' => 'linkedin', 'action' => 'send_message', 'label' => 'Message'],
                    ],
                    'not_accepted' => [
                        ['key' => 5, 'type' => 'action', 'channel' => 'email', 'action' => 'send_email', 'label' => 'Email'],
                    ],
                ]],
            ],
        ]);
    }

    private function campaignWithEmailCondition(User $user): V2OutreachCampaign
    {
        return V2OutreachCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'name' => 'Email Condition Campaign',
            'status' => 'running',
            'node_model' => [
                ['key' => 1, 'type' => 'action', 'channel' => 'email', 'action' => 'send_email', 'label' => 'Email'],
                ['key' => 2, 'type' => 'condition', 'channel' => 'email', 'condition' => 'email_opened', 'label' => 'Opened?', 'branches' => [
                    'accepted' => [],
                    'not_accepted' => [],
                ]],
            ],
        ]);
    }
}

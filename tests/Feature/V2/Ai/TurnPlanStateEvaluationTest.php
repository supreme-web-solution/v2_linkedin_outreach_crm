<?php

namespace Tests\Feature\V2\Ai;

use App\Models\SnLead;
use App\Models\SnLeadList;
use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLead;
use App\Models\V2OutreachNodeEvent;
use App\V2\Ai\Services\PostExecutionVerifierService;
use App\V2\Ai\Services\TurnPlanStateEvaluationService;
use App\V2\Outreach\OutreachExecutionMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TurnPlanStateEvaluationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_existing_reuse_outreach_selects_existing_without_discovery_requirement(): void
    {
        [$user, $org] = $this->userWithOrg();
        $this->seedSaasFounders($user, 37);

        $eval = $this->evaluatePlan($user, $org->id, [
            'required_outcome' => 'send_now',
            'constraints' => [
                'data_preference' => 'reuse_existing_first',
                'reuse_first' => true,
                'target_segment' => 'SaaS founders',
            ],
            'objective' => ['segment' => 'SaaS founders'],
            'semantic' => ['data_preference' => 'reuse_existing_first'],
        ]);

        $this->assertSame(37, $eval['existing_eligible_count']);
        $this->assertFalse($eval['requires_external_discovery']);
        $this->assertNull($eval['new_required']);
        $this->assertSame(0, $eval['remaining_discovery']);
    }

    public function test_b_new_only_requires_full_quantity_not_minus_existing(): void
    {
        [$user, $org] = $this->userWithOrg();
        $this->seedSaasFounders($user, 37);

        $eval = $this->evaluatePlan($user, $org->id, [
            'required_outcome' => 'send_now',
            'measurable_expectations' => ['target_count' => 100],
            'constraints' => [
                'new_only' => true,
                'target_count' => 100,
                'target_segment' => 'SaaS founders',
            ],
            'objective' => ['segment' => 'SaaS founders'],
            'semantic' => ['new_only' => true, 'target_segment' => 'SaaS founders'],
        ]);

        $this->assertTrue($eval['new_only']);
        $this->assertSame(100, $eval['new_required']);
        $this->assertSame(100, $eval['remaining_discovery']);
        $this->assertSame(37, $eval['existing_eligible_count']);
        $this->assertTrue($eval['requires_external_discovery']);
    }

    public function test_c_reuse_without_new_only_counts_existing_toward_quantity(): void
    {
        [$user, $org] = $this->userWithOrg();
        $this->seedSaasFounders($user, 37);

        $eval = $this->evaluatePlan($user, $org->id, [
            'required_outcome' => 'send_now',
            'measurable_expectations' => ['target_count' => 100],
            'constraints' => [
                'target_count' => 100,
                'target_segment' => 'SaaS founders',
            ],
            'objective' => ['segment' => 'SaaS founders'],
        ]);

        $this->assertFalse($eval['new_only']);
        $this->assertSame(37, $eval['existing_eligible_count']);
        $this->assertSame(63, $eval['remaining_discovery']);
        $this->assertTrue($eval['requires_external_discovery']);
    }

    public function test_d_exclude_previously_contacted_reduces_eligible_count(): void
    {
        [$user, $org] = $this->userWithOrg();
        $list = $this->seedSaasFounders($user, 100);
        $this->markLeadsContacted($user, $org->id, $list, 20);

        $eval = $this->evaluatePlan($user, $org->id, [
            'required_outcome' => 'find_only',
            'measurable_expectations' => ['target_count' => 100],
            'constraints' => [
                'exclude_contacted' => true,
                'target_count' => 100,
                'target_segment' => 'SaaS founders',
            ],
            'objective' => ['segment' => 'SaaS founders'],
        ]);

        $this->assertSame(100, $eval['candidate_count']);
        $this->assertSame(20, $eval['previously_contacted_count']);
        $this->assertSame(20, $eval['excluded_count']);
        $this->assertSame(80, $eval['eligible_count']);
        $this->assertSame(20, $eval['remaining_deficit']);
    }

    public function test_e_whatsapp_eligibility_counts_verified_provider_ids_only(): void
    {
        [$user, $org] = $this->userWithOrg();
        $list = $this->seedSaasFounders($user, 100, whatsAppEligible: 22);

        $eval = $this->evaluatePlan($user, $org->id, [
            'required_outcome' => 'send_now',
            'measurable_expectations' => ['target_count' => 100],
            'constraints' => [
                'preferred_channel' => 'whatsapp',
                'target_count' => 100,
                'target_segment' => 'SaaS founders',
            ],
            'objective' => ['segment' => 'SaaS founders'],
        ]);

        $this->assertSame('whatsapp', $eval['channel']);
        $this->assertSame(22, $eval['channel_eligible_count']);
        $this->assertSame(78, $eval['channel_ineligible_count']);
        $this->assertSame(22, $eval['eligible_count']);
        $this->assertSame(78, $eval['remaining_deficit']);
    }

    public function test_f_combined_constraints_apply_together(): void
    {
        [$user, $org] = $this->userWithOrg();
        $list = $this->seedSaasFounders($user, 50, whatsAppEligible: 15);
        $this->markLeadsContacted($user, $org->id, $list, 10);

        $eval = $this->evaluatePlan($user, $org->id, [
            'required_outcome' => 'setup_only',
            'measurable_expectations' => ['target_count' => 50],
            'constraints' => [
                'exclude_contacted' => true,
                'preferred_channel' => 'whatsapp',
                'target_count' => 50,
                'target_segment' => 'SaaS founders',
            ],
            'objective' => ['segment' => 'SaaS founders'],
        ]);

        $this->assertContains('exclude_previously_contacted', $eval['constraints_applied']);
        $this->assertContains('whatsapp', $eval['constraints_applied']);
        $this->assertSame(50, $eval['candidate_count']);
        $this->assertSame(10, $eval['excluded_count']);
        $this->assertSame(40, $eval['existing_eligible_count']);
        $this->assertLessThanOrEqual(15, $eval['channel_eligible_count']);
    }

    public function test_g_tenant_isolation_prevents_cross_user_counts(): void
    {
        [$userA, $orgA] = $this->userWithOrg();
        [$userB, $orgB] = $this->userWithOrg();

        $this->seedSaasFounders($userA, 37);
        $this->seedSaasFounders($userB, 5);

        $evalA = $this->evaluatePlan($userA, $orgA->id, [
            'required_outcome' => 'send_now',
            'constraints' => ['target_segment' => 'SaaS founders', 'reuse_first' => true],
            'objective' => ['segment' => 'SaaS founders'],
        ]);

        $evalB = $this->evaluatePlan($userB, $orgB->id, [
            'required_outcome' => 'send_now',
            'constraints' => ['target_segment' => 'SaaS founders', 'reuse_first' => true],
            'objective' => ['segment' => 'SaaS founders'],
        ]);

        $this->assertSame(37, $evalA['existing_eligible_count']);
        $this->assertSame(5, $evalB['existing_eligible_count']);
    }

    public function test_h_truthful_execution_metrics_distinguish_success_and_failure(): void
    {
        [$user, $org] = $this->userWithOrg();

        $campaign = V2OutreachCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'Metrics test',
            'template_type' => 'whatsapp_only',
            'status' => 'active',
            'node_model' => [],
        ]);

        for ($i = 0; $i < 80; $i++) {
            V2OutreachNodeEvent::query()->create([
                'outreach_campaign_id' => $campaign->id,
                'node_key' => 'wa_1',
                'channel' => 'whatsapp',
                'action' => 'send',
                'status' => 'sent',
                'message' => 'ok',
                'payload' => [],
                'executed_at' => now(),
            ]);
        }
        for ($i = 0; $i < 20; $i++) {
            V2OutreachNodeEvent::query()->create([
                'outreach_campaign_id' => $campaign->id,
                'node_key' => 'wa_1',
                'channel' => 'whatsapp',
                'action' => 'send',
                'status' => 'failed',
                'message' => 'fail',
                'payload' => [],
                'executed_at' => now(),
            ]);
        }

        $metrics = app(OutreachExecutionMetricsService::class)->forCampaign($campaign);
        $this->assertSame(100, $metrics['attempted']);
        $this->assertSame(80, $metrics['successful']);
        $this->assertSame(20, $metrics['failed']);

        $verifier = app(PostExecutionVerifierService::class);
        $snapshot = $verifier->snapshot($user, $org->id);
        $this->assertSame(100, $snapshot['execution_metrics']['attempted']);
        $this->assertSame(80, $snapshot['execution_metrics']['successful']);
        $this->assertSame(20, $snapshot['execution_metrics']['failed']);

        $plan = [
            'required_outcome' => 'send_now',
            'measurable_expectations' => ['target_count' => 100],
            'constraints' => ['preferred_channel' => 'whatsapp'],
            'state_evaluation' => [
                'channel' => 'whatsapp',
                'channel_eligible_count' => 22,
            ],
        ];
        $verification = $verifier->verify($user, $org->id, $plan, $snapshot, $snapshot);
        $this->assertNotEmpty($verification['warnings']);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function evaluatePlan(User $user, int $orgId, array $plan): array
    {
        return app(TurnPlanStateEvaluationService::class)->evaluate($user, $orgId, $plan);
    }

    private function seedSaasFounders(User $user, int $count, int $whatsAppEligible = 0): SnLeadList
    {
        $list = SnLeadList::query()->create([
            'user_id' => $user->id,
            'list_hash' => 'saas-'.uniqid(),
            'name' => 'SaaS founders',
        ]);

        for ($i = 0; $i < $count; $i++) {
            SnLead::query()->create([
                'sn_list_id' => $list->list_hash,
                'first_name' => 'Founder',
                'last_name' => (string) $i,
                'email' => "founder{$i}@saas.test",
                'phone' => '+1555'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
                'lid' => "lid-{$i}",
                'whatsapp_provider_id' => $i < $whatsAppEligible
                    ? '1555'.str_pad((string) $i, 7, '0', STR_PAD_LEFT).'@s.whatsapp.net'
                    : null,
            ]);
        }

        return $list;
    }

    private function markLeadsContacted(User $user, int $orgId, SnLeadList $list, int $count): void
    {
        $campaign = V2OutreachCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $orgId,
            'name' => 'Prior outreach',
            'template_type' => 'linkedin_only',
            'status' => 'active',
            'node_model' => [],
        ]);

        $leads = SnLead::query()->where('sn_list_id', $list->list_hash)->limit($count)->get();
        foreach ($leads as $lead) {
            V2OutreachLead::query()->create([
                'outreach_campaign_id' => $campaign->id,
                'source_list_src' => 'sn',
                'source_record_id' => $lead->id,
                'provider_profile_id' => $lead->lid,
                'email' => $lead->email,
                'phone' => $lead->phone,
                'full_name' => trim($lead->first_name.' '.$lead->last_name),
                'status' => 'running',
                'meta' => [],
            ]);
        }
    }

    /**
     * @return array{0:User, 1:V2Organization}
     */
    private function userWithOrg(): array
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'State Eval Org '.uniqid(),
            'slug' => 'state-eval-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        $user->forceFill(['current_organization_id' => $org->id])->save();
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        return [$user->fresh(), $org];
    }
}

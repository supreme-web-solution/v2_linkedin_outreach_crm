<?php

namespace Tests\Unit\V2\Ai;

use App\Models\AiActionApproval;
use App\Models\SnLead;
use App\Models\SnLeadList;
use App\Models\User;
use App\Models\V2IntegrationAccount;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Ai\Services\CommandCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommandCenterControlCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_bare_launch_blocked_without_prospect_list(): void
    {
        [$user, $org, $approval] = $this->pendingStrategyApproval('Nigeria SaaS founders');

        $result = app(CommandCenterService::class)->handleControlCommand($user, $org->id, 'Launch');

        $this->assertTrue($result['handled'] ?? false);
        $this->assertSame('blocked_launch', $result['decision'] ?? null);
        $this->assertStringContainsString("won't create a campaign", (string) ($result['reply'] ?? ''));
        $this->assertSame('pending', $approval->fresh()->status);
    }

    public function test_bare_launch_approves_when_matching_list_exists(): void
    {
        [$user, $org, $approval] = $this->pendingStrategyApproval('Nigeria SaaS founders');

        $list = SnLeadList::query()->create([
            'user_id' => $user->id,
            'name' => 'Nigeria SaaS founders list',
            'list_hash' => 'hash-nigeria-saas',
        ]);
        SnLead::query()->create([
            'user_id' => $user->id,
            'sn_list_id' => $list->list_hash,
            'first_name' => 'Ada',
            'last_name' => 'Okon',
        ]);

        $this->connectPrimaryOutreachChannels($user);

        $result = app(CommandCenterService::class)->handleControlCommand($user, $org->id, 'Launch');

        $this->assertTrue($result['handled'] ?? false);
        $this->assertSame('approve', $result['decision'] ?? null);
        $this->assertSame('executed', $approval->fresh()->status);
        $this->assertStringContainsString('Audience:', (string) ($result['reply'] ?? ''));
    }

    public function test_second_launch_explains_plan_already_used(): void
    {
        [$user, $org, $approval] = $this->pendingIcpApproval('Dev shops using AI tools');

        app(CommandCenterService::class)->handleControlCommand($user, $org->id, 'LAUNCH '.$approval->id);
        $approval->refresh();
        $this->assertSame('approved', $approval->status);

        $result = app(CommandCenterService::class)->handleControlCommand($user, $org->id, 'LAUNCH '.$approval->id);

        $this->assertTrue($result['handled'] ?? false);
        $this->assertStringContainsString('already launched', (string) ($result['reply'] ?? ''));
        $this->assertStringNotContainsString("couldn't find", (string) ($result['reply'] ?? ''));
    }

    public function test_go_ahead_launches_newest_pending_plan(): void
    {
        [$user, $org, $approval] = $this->pendingIcpApproval('Dev shops using AI tools');

        $result = app(CommandCenterService::class)->handleControlCommand($user, $org->id, 'go ahead');

        $this->assertTrue($result['handled'] ?? false);
        $this->assertSame('approve', $result['decision'] ?? null);
        $this->assertSame('approved', $approval->fresh()->status);
    }

    public function test_go_ahead_rewrites_to_discover_when_outreach_plan_lacks_audience(): void
    {
        [$user, $org, $approval] = $this->pendingStrategyApproval('Laravel developers');

        $result = app(CommandCenterService::class)->handleControlCommand($user, $org->id, 'go ahead');

        $this->assertFalse($result['handled'] ?? true);
        $this->assertStringContainsString('discover_prospects', (string) ($result['rewrite'] ?? ''));
        $this->assertSame('pending', $approval->fresh()->status);
    }

    public function test_launch_blocks_when_linkedin_not_connected_for_campaign_plan(): void
    {
        [$user, $org, $approval] = $this->pendingStrategyApproval('US dev agencies');

        $list = SnLeadList::query()->create([
            'user_id' => $user->id,
            'name' => 'US dev agencies',
            'list_hash' => 'hash-us-dev',
        ]);
        SnLead::query()->create([
            'user_id' => $user->id,
            'sn_list_id' => $list->list_hash,
            'first_name' => 'Sam',
            'last_name' => 'Lee',
        ]);

        $approval->update([
            'payload' => array_merge($approval->payload ?? [], [
                'list_hash' => $list->list_hash,
                'list_src' => 'sn',
                'list_name' => $list->name,
            ]),
        ]);

        $result = app(CommandCenterService::class)->handleControlCommand($user, $org->id, 'LAUNCH '.$approval->id);

        $this->assertSame('blocked_launch', $result['decision'] ?? null);
        $this->assertStringContainsString('LinkedIn', (string) ($result['reply'] ?? ''));
        $this->assertStringContainsString('Integrations', (string) ($result['reply'] ?? ''));
        $this->assertSame('pending', $approval->fresh()->status);
    }

    /**
     * @return array{0: User, 1: V2Organization, 2: AiActionApproval}
     */
    private function pendingStrategyApproval(string $goal): array
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Control Org',
            'slug' => 'control-org-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $approval = AiActionApproval::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'tool' => 'propose_strategy',
            'permission' => 'prepare',
            'status' => 'pending',
            'payload' => [
                'type' => 'strategy',
                'goal' => $goal,
                'icp_notes' => $goal,
                'geography' => 'Nigeria',
            ],
        ]);

        return [$user, $org, $approval];
    }

    private function connectPrimaryOutreachChannels(User $user): void
    {
        foreach (['linkedin', 'email'] as $provider) {
            V2IntegrationAccount::query()->create([
                'user_id' => $user->id,
                'provider' => $provider,
                'provider_account_id' => $provider.'_acc_'.uniqid(),
                'status' => 'active',
            ]);
        }
    }

    /**
     * @return array{0: User, 1: V2Organization, 2: AiActionApproval}
     */
    private function pendingIcpApproval(string $goal): array
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'ICP Org',
            'slug' => 'icp-org-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $approval = AiActionApproval::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'tool' => 'build_icp',
            'permission' => 'prepare',
            'status' => 'pending',
            'payload' => [
                'type' => 'icp',
                'goal' => $goal,
                'icp' => [
                    'summary' => $goal,
                    'geography' => 'Global',
                ],
            ],
        ]);

        return [$user, $org, $approval];
    }
}

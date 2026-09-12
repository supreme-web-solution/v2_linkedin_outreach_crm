<?php

namespace Tests\Feature\V2\Ai;

use App\Models\AiActionApproval;
use App\Models\SnLead;
use App\Models\SnLeadList;
use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Ai\Services\WorkflowDiscoveryStepHandler;
use App\V2\Ai\Services\WorkflowRuntimeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class WorkflowOutreachContinuationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_send_now_waits_for_approval_after_discovery_and_prepare(): void
    {
        [$user, $org] = $this->userWithOrg();
        $list = $this->seedSaasFounders($user, 37);
        $plan = $this->outreachPlan(100);

        $this->simulateDiscoveryHandler([
            fn () => $this->addFounders($user, $list, 55, startIndex: 9000),
            fn () => $this->addFounders($user, $list, 8, startIndex: 9100),
        ]);

        $runtime = app(WorkflowRuntimeService::class);
        $run = $runtime->start($user, $org->id, $plan);

        $runtime->tick($run->id);
        $runtime->tick($run->fresh()->id);
        $runtime->tick($run->fresh()->id);
        $result = $runtime->tick($run->fresh()->id);

        $run = $run->fresh();
        $this->assertSame('waiting', $run->status);
        $this->assertTrue($run->meta['prepared'] ?? false);
        $this->assertTrue($run->meta['awaiting_approval'] ?? false);
        $this->assertGreaterThan(0, (int) ($run->meta['approval_id'] ?? 0));
        $this->assertTrue($result['waiting'] ?? false);

        $prepare = $run->steps()->where('step_key', 'prepare_outreach')->first();
        $this->assertNotNull($prepare);
        $this->assertSame('completed', $prepare->status);

        $awaiting = $run->steps()->where('step_key', 'awaiting_approval')->first();
        $this->assertNotNull($awaiting);
        $this->assertSame('waiting', $awaiting->status);

        $approval = AiActionApproval::query()->find($run->meta['approval_id']);
        $this->assertNotNull($approval);
        $this->assertSame('pending', $approval->status);
        $this->assertSame($run->id, (int) $approval->workflow_run_id);
    }

    public function test_send_now_completes_after_launch(): void
    {
        [$user, $org] = $this->userWithOrg();
        $list = $this->seedSaasFounders($user, 100);
        $plan = $this->outreachPlan(100);

        $this->simulateDiscoveryHandler([fn () => null]);

        $runtime = app(WorkflowRuntimeService::class);
        $run = $runtime->start($user, $org->id, $plan);
        $runtime->tick($run->id);
        $runtime->tick($run->fresh()->id);

        $run = $run->fresh();
        $approvalId = (int) $run->meta['approval_id'];

        $runtime->resumeAfterLaunch($run->id, $approvalId, [
            'outreach_campaign_id' => 42,
            'campaign_status' => 'active',
        ]);

        $run = $run->fresh();
        $this->assertSame('completed', $run->status);
        $this->assertTrue($run->meta['executed'] ?? false);
        $this->assertSame(42, (int) ($run->meta['outreach_campaign_id'] ?? 0));

        $awaiting = $run->steps()->where('step_key', 'awaiting_approval')->first();
        $this->assertSame('completed', $awaiting->status);
    }

    public function test_setup_only_completes_after_prepare_without_launch(): void
    {
        [$user, $org] = $this->userWithOrg();
        $this->seedSaasFounders($user, 100);
        $plan = $this->outreachPlan(100, outcome: 'setup_only');

        $this->simulateDiscoveryHandler([fn () => null]);

        $runtime = app(WorkflowRuntimeService::class);
        $run = $runtime->start($user, $org->id, $plan);
        $runtime->tick($run->id);
        $runtime->tick($run->fresh()->id);

        $run = $run->fresh();
        $this->assertSame('completed', $run->status);
        $this->assertTrue($run->meta['prepared'] ?? false);
        $this->assertFalse($run->meta['executed'] ?? false);
        $this->assertNull($run->steps()->where('step_key', 'awaiting_approval')->first());
    }

    /**
     * @param  list<callable>  $handlers
     */
    private function simulateDiscoveryHandler(array $handlers): void
    {
        $index = 0;
        $mock = Mockery::mock(WorkflowDiscoveryStepHandler::class);
        $mock->shouldReceive('execute')->andReturnUsing(function () use ($handlers, &$index) {
            if (isset($handlers[$index])) {
                ($handlers[$index])();
            }
            $index++;

            return [
                'step_type' => 'discover',
                'requested_count' => 0,
                'provider_returned' => 0,
                'saved_reported' => 0,
            ];
        });
        $this->app->instance(WorkflowDiscoveryStepHandler::class, $mock);
    }

    private function outreachPlan(int $quantity, string $outcome = 'send_now'): array
    {
        return [
            'required_outcome' => $outcome,
            'goal' => 'outreach',
            'measurable_expectations' => ['target_count' => $quantity],
            'constraints' => [
                'target_count' => $quantity,
                'target_segment' => 'SaaS founders',
            ],
            'objective' => ['segment' => 'SaaS founders'],
        ];
    }

    private function seedSaasFounders(User $user, int $count): SnLeadList
    {
        $list = SnLeadList::query()->create([
            'user_id' => $user->id,
            'list_hash' => 'saas-'.uniqid(),
            'name' => 'SaaS founders',
        ]);

        $this->addFounders($user, $list, $count);

        return $list;
    }

    private function addFounders(User $user, SnLeadList $list, int $count, int $startIndex = 0): void
    {
        for ($i = 0; $i < $count; $i++) {
            $idx = $startIndex + $i;
            SnLead::query()->create([
                'sn_list_id' => $list->list_hash,
                'first_name' => 'Founder',
                'last_name' => (string) $idx,
                'email' => "founder{$idx}@saas.test",
                'phone' => '+1555'.str_pad((string) $idx, 7, '0', STR_PAD_LEFT),
                'lid' => "lid-{$idx}",
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
            'name' => 'Outreach Org '.uniqid(),
            'slug' => 'out-'.uniqid(),
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

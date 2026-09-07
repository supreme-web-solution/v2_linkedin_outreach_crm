<?php

namespace Tests\Unit\V2\Ai;

use App\Models\User;
use App\Models\V2IntegrationAccount;
use App\V2\Ai\Services\PlanFunnelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanFunnelServiceTest extends TestCase
{
    use RefreshDatabase;
    public function test_builds_funnel_with_blocked_contacts_and_integrations(): void
    {
        $user = User::factory()->create();

        $funnel = app(PlanFunnelService::class)->forPlan([
            'type' => 'campaign',
            'goal' => 'Book 10 meetings',
            'audience' => 'SaaS founders in Nigeria',
            'source' => 'LinkedIn search',
            'preferred_channels' => 'LinkedIn + Email',
            'target_count' => 200,
            'sequence' => ['Connect', 'Wait', 'Message'],
        ], $user);

        $this->assertCount(6, $funnel);
        $this->assertSame('audience', $funnel[0]['step']);
        $this->assertSame('ready', $funnel[0]['status']);
        $this->assertSame('blocked', $funnel[2]['status']);
        $this->assertSame('blocked', $funnel[3]['status']);
    }

    public function test_ready_when_list_and_integrations_present(): void
    {
        $user = User::factory()->create();
        V2IntegrationAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_account_id' => 'li_acc_'.uniqid(),
            'status' => 'active',
        ]);
        V2IntegrationAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'email',
            'provider_account_id' => 'email_acc_'.uniqid(),
            'status' => 'active',
        ]);

        $funnel = app(PlanFunnelService::class)->forPlan([
            'type' => 'strategy',
            'goal' => 'Book meetings',
            'icp_notes' => 'Agencies',
            'preferred_channels' => 'LinkedIn + Email',
            'list_hash' => 'imp-abc123',
            'list_name' => 'Agency list',
        ], $user);

        $this->assertSame('ready', $funnel[2]['status']);
        $this->assertSame('ready', $funnel[3]['status']);
    }
}

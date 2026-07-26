<?php

namespace Tests\Feature\Web;

use App\Jobs\V2\LaunchCallFromLeadJob;
use App\Models\User;
use App\Models\V2Call;
use App\Models\V2IntegrationAccount;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Services\UnipileDailyActionLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CallLaunchPacingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('billing.require_entitlement', false);
    }

    public function test_bulk_chat_launch_staggers_job_delays(): void
    {
        Queue::fake();
        Config::set('services.unipile_pacing.chat_launch_stagger_seconds', 10);
        Config::set('services.unipile_pacing.chat_launch_jitter_seconds', 0);

        $user = $this->userWithOrgAndLinkedIn();

        foreach (['prospect-a', 'prospect-b', 'prospect-c'] as $i => $connectionId) {
            V2Call::query()->create([
                'user_id' => $user->id,
                'organization_id' => $user->current_organization_id,
                'connection_id' => $connectionId,
                'prospect_name' => 'Prospect '.$i,
                'status' => 'pending',
                'meta' => ['batch_id' => 'batch-pacing', 'batch_name' => 'Pacing flow'],
            ]);
        }

        $response = $this->actingAs($user)->post('/calls/flows/batch-pacing/launch-chats');

        $response->assertRedirect();
        Queue::assertPushed(LaunchCallFromLeadJob::class, 3);

        $delays = Queue::pushed(LaunchCallFromLeadJob::class)
            ->map(fn ($job) => $job->delay ? now()->diffInSeconds($job->delay, false) : 0)
            ->sort()
            ->values();

        $this->assertEqualsWithDelta(0, $delays[0], 3);
        $this->assertEqualsWithDelta(10, $delays[1], 3);
        $this->assertEqualsWithDelta(20, $delays[2], 3);
    }

    public function test_bulk_launch_blocked_with_toast_when_daily_chat_cap_reached(): void
    {
        Queue::fake();
        Config::set('services.unipile_pacing.daily_new_chats', 1);

        $user = $this->userWithOrgAndLinkedIn();

        V2Call::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'connection_id' => 'prospect-capped',
            'prospect_name' => 'Capped Prospect',
            'status' => 'pending',
            'meta' => ['batch_id' => 'batch-capped', 'batch_name' => 'Capped flow'],
        ]);

        app(UnipileDailyActionLimiter::class)->tryConsume($user->id, UnipileDailyActionLimiter::ACTION_NEW_CHATS);

        $response = $this->actingAs($user)->post('/calls/flows/batch-capped/launch-chats');

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertStringContainsString('Daily LinkedIn chat limit reached', session('error'));
        Queue::assertNotPushed(LaunchCallFromLeadJob::class);
    }

    public function test_single_chat_launch_blocked_with_toast_when_cap_reached(): void
    {
        Queue::fake();
        Config::set('services.unipile_pacing.daily_new_chats', 1);

        $user = $this->userWithOrgAndLinkedIn();

        $call = V2Call::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'connection_id' => 'prospect-single',
            'prospect_name' => 'Single Prospect',
            'status' => 'pending',
        ]);

        app(UnipileDailyActionLimiter::class)->tryConsume($user->id, UnipileDailyActionLimiter::ACTION_NEW_CHATS);

        $response = $this->actingAs($user)->post("/calls/{$call->id}/launch-chat");

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertStringContainsString('Daily LinkedIn chat limit reached', session('error'));
        Queue::assertNotPushed(LaunchCallFromLeadJob::class);
    }

    private function userWithOrgAndLinkedIn(): User
    {
        $user = User::factory()->create();
        $organization = V2Organization::query()->create([
            'name' => 'Pacing Org',
            'slug' => 'pacing-org-'.$user->id,
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

        V2IntegrationAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_account_id' => 'acc_pacing_test',
            'status' => 'active',
            'meta' => ['unipile_account_id' => 'acc_pacing_test'],
        ]);

        return $user->fresh();
    }
}

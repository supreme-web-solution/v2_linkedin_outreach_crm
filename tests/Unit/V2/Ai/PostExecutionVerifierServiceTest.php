<?php

namespace Tests\Unit\V2\Ai;

use App\Models\User;
use App\Models\V2Campaign;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\Models\V2OutreachCampaign;
use App\V2\Ai\Services\PostExecutionVerifierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostExecutionVerifierServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_detects_find_only_side_effect_campaign_creation(): void
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Verifier Org',
            'slug' => 'verifier-org-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $svc = app(PostExecutionVerifierService::class);
        $before = $svc->snapshot($user, $org->id);

        V2OutreachCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'Unexpected Campaign',
            'status' => 'created',
        ]);
        V2Campaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'Unexpected LinkedIn Campaign',
            'status' => 'created',
        ]);

        $after = $svc->snapshot($user, $org->id);
        $result = $svc->verify($user, $org->id, [
            'required_outcome' => 'find_only',
        ], $before, $after);

        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['warnings']);
    }

    public function test_skips_channel_eligible_warning_while_workflow_discovery_runs(): void
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Async Org',
            'slug' => 'async-org-'.uniqid(),
            'owner_id' => $user->id,
        ]);

        $svc = app(PostExecutionVerifierService::class);
        $snapshot = $svc->snapshot($user, $org->id);

        $result = $svc->verify($user, $org->id, [
            'required_outcome' => 'find_only',
            'workflow_run_id' => 5,
            'measurable_expectations' => ['target_count' => 50],
            'state_evaluation' => [
                'channel_eligible_count' => 0,
                'channel' => 'instagram',
            ],
        ], $snapshot, $snapshot);

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['warnings']);
    }
}

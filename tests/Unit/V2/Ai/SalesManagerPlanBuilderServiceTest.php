<?php

namespace Tests\Unit\V2\Ai;

use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\Models\V2OutreachCampaign;
use App\V2\Ai\Services\SalesManagerPlanBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesManagerPlanBuilderServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_build_includes_activate_for_draft_with_list(): void
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'SM Org',
            'slug' => 'sm-org-'.uniqid(),
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
            'name' => 'Ready draft',
            'status' => 'draft',
            'node_model' => [],
        ]);
        $campaign->outreachLists()->create([
            'list_hash' => 'hash-ready',
            'list_src' => 'csv',
            'list_name' => 'Ready list',
        ]);

        $plan = app(SalesManagerPlanBuilderService::class)->build($user, $org->id, 0, 0, 0, 0, 2);

        $this->assertFalse($plan['empty']);
        $this->assertSame(1, $plan['counts']['activate_campaign']);
        $this->assertSame('activate_campaign', $plan['actions'][0]['type']);
    }
}

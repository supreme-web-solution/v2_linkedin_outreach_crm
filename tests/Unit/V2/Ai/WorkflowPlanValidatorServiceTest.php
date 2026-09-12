<?php

namespace Tests\Unit\V2\Ai;

use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Ai\Services\WorkflowPlanValidatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowPlanValidatorServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_oversized_target_count(): void
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Validator Org',
            'slug' => 'validator-org-'.uniqid(),
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $result = app(WorkflowPlanValidatorService::class)->validate(
            $user,
            $org->id,
            'draft_campaign_plan',
            ['target_count' => 1000, 'channels' => 'linkedin+email'],
        );

        $this->assertFalse($result['ok']);
    }

    public function test_rejects_invalid_schedule_time(): void
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Validator Org 2',
            'slug' => 'validator-org2-'.uniqid(),
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $result = app(WorkflowPlanValidatorService::class)->validate(
            $user,
            $org->id,
            'draft_campaign_plan',
            ['scheduled_at' => now()->subDay()->toIso8601String()],
        );

        $this->assertFalse($result['ok']);
    }

    public function test_accepts_single_resource_delete_scope(): void
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Validator Org 3',
            'slug' => 'validator-org3-'.uniqid(),
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $result = app(WorkflowPlanValidatorService::class)->validate(
            $user,
            $org->id,
            'delete_resource',
            [
                'type' => 'resource_delete',
                'kind' => 'lead_list',
                'resource_id' => 'abc123',
                'destructive' => true,
            ],
        );

        $this->assertTrue($result['ok'], implode(' ', $result['errors']));
    }

    public function test_accepts_bulk_delete_items(): void
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Validator Org 4',
            'slug' => 'validator-org4-'.uniqid(),
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $result = app(WorkflowPlanValidatorService::class)->validate(
            $user,
            $org->id,
            'delete_resource',
            [
                'type' => 'bulk_delete',
                'destructive' => true,
                'items' => [
                    ['kind' => 'outreach', 'resource_id' => '18'],
                    ['kind' => 'lead_list', 'resource_id' => 'xyz', 'list_src' => 'csv'],
                ],
            ],
        );

        $this->assertTrue($result['ok'], implode(' ', $result['errors']));
    }
}

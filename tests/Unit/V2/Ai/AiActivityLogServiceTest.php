<?php

namespace Tests\Unit\V2\Ai;

use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Ai\Services\AiActivityLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiActivityLogServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_records_and_filters_today_activity(): void
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Activity Org',
            'slug' => 'activity-org-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $svc = app(AiActivityLogService::class);
        $svc->record($user, $org->id, null, 'draft_campaign_plan', 'campaign_staged', 'campaign', '35', ['name' => 'A']);
        $svc->record($user, $org->id, null, 'delete_campaign', 'delete_requested', 'campaign', '35', ['name' => 'A']);

        $rows = $svc->queryForUser(
            $user,
            $org->id,
            'delete_requested',
            'campaign',
            now()->startOfDay()->toIso8601String(),
            now()->endOfDay()->toIso8601String(),
            20
        );

        $this->assertCount(1, $rows);
        $this->assertSame('delete_requested', $rows[0]['action']);
    }
}

<?php

namespace Tests\Unit\V2\Ai;

use App\Models\AiEmployeeSetting;
use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Ai\Services\WorkspaceContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceDiscoveryHintsTest extends TestCase
{
    use RefreshDatabase;

    public function test_uses_onboarding_audience_words_not_a_canned_title(): void
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Clinic Org',
            'slug' => 'clinic-org-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        AiEmployeeSetting::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'enabled' => true,
            'autonomy_level' => 2,
            'meta' => [
                'stored_icp' => [
                    'icp' => [
                        'decision_maker' => 'Clinic administrators and hospital directors',
                        'industry' => 'Private hospitals',
                        'search_query' => 'clinic administrators private hospitals Kenya',
                        'search_titles' => ['Clinic Administrator'],
                        'geography' => 'Kenya',
                    ],
                ],
            ],
        ]);

        $hints = app(WorkspaceContextService::class)->discoverySearchHints($user, $org->id, []);

        $this->assertSame('clinic administrators private hospitals Kenya', $hints['query']);
        $this->assertSame('Clinic Administrator', $hints['title']);
        $this->assertSame('Kenya', $hints['geography']);
        $this->assertStringNotContainsString('CTO', $hints['query']);
        $this->assertStringNotContainsString('Founder', (string) $hints['title']);
    }

    public function test_interpreter_handoff_wins_over_stored_icp(): void
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Farm Org',
            'slug' => 'farm-org-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        AiEmployeeSetting::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'enabled' => true,
            'autonomy_level' => 2,
            'meta' => [
                'stored_icp' => [
                    'icp' => [
                        'decision_maker' => 'Clinic administrators',
                    ],
                ],
            ],
        ]);

        $hints = app(WorkspaceContextService::class)->discoverySearchHints($user, $org->id, [
            'semantic' => [
                'handoff_query' => 'co-op farm managers in Kenya',
                'target_segment' => 'co-op farm managers',
                'geography' => 'Kenya',
            ],
            'objective' => ['segment' => 'co-op farm managers'],
        ]);

        $this->assertSame('co-op farm managers in Kenya', $hints['query']);
        $this->assertSame('co-op farm managers', $hints['title']);
        $this->assertSame('Kenya', $hints['geography']);
    }
}

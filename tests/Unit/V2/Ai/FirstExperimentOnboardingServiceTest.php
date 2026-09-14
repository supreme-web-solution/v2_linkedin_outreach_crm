<?php

namespace Tests\Unit\V2\Ai;

use App\Models\AiActionApproval;
use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Ai\Services\AiEmployeeSettingsService;
use App\V2\Ai\Services\FirstExperimentOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FirstExperimentOnboardingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_stages_first_conversation_experiment_once(): void
    {
        [$user, $org] = $this->userWithOrg();

        $first = app(FirstExperimentOnboardingService::class)->stageAfterComplete($user, $org->id);

        $this->assertTrue($first['staged']);
        $this->assertNotNull($first['approval_id']);
        $this->assertSame('no_searchable_channel', $first['blocked_reason']);

        $approval = AiActionApproval::query()->find($first['approval_id']);
        $this->assertNotNull($approval);
        $this->assertSame('draft_campaign_plan', $approval->tool);
        $this->assertTrue((bool) ($approval->payload['first_experiment'] ?? false));
        $this->assertSame(40, (int) ($approval->payload['target_count'] ?? 0));
        $this->assertTrue((bool) ($approval->payload['pause_on_reply'] ?? false));
        $this->assertContains('linkedin', $approval->payload['discovery_channels'] ?? []);
        $this->assertTrue((bool) ($approval->payload['include_email'] ?? false));

        $settings = app(AiEmployeeSettingsService::class)->for($user, $org->id);
        $this->assertNotEmpty(data_get($settings->meta, 'first_experiment_staged_at'));
        $this->assertTrue((bool) data_get($settings->meta, 'acquisition_experiment.active'));

        $second = app(FirstExperimentOnboardingService::class)->stageAfterComplete($user, $org->id);
        $this->assertFalse($second['staged']);
        $this->assertSame('already_staged', $second['blocked_reason']);
        $this->assertSame(1, AiActionApproval::query()->where('tool', 'draft_campaign_plan')->count());
    }

    /**
     * @return array{0: User, 1: V2Organization}
     */
    private function userWithOrg(): array
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'First Experiment Org',
            'slug' => 'first-exp-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $user->forceFill(['current_organization_id' => $org->id])->save();

        return [$user->fresh(), $org];
    }
}

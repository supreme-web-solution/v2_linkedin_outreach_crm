<?php

namespace Tests\Unit\V2\Ai;

use App\Models\AiEmployeeSetting;
use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Ai\Services\WorkflowDiscoveryAckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowDiscoveryAckServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ack_is_short_and_has_no_research_note(): void
    {
        [$user, $org] = $this->fixtures();

        $plan = [
            'required_outcome' => 'find_only',
            'state_evaluation' => ['requested_quantity' => 50],
            'constraints' => ['preferred_channel' => 'instagram'],
        ];

        $text = app(WorkflowDiscoveryAckService::class)->build($user, $org->id, $plan);

        $this->assertStringContainsString('50 fresh prospects', $text);
        $this->assertStringContainsString('Instagram', $text);
        $this->assertStringContainsString('background', $text);
        $this->assertStringNotContainsString('Research note', $text);
        $this->assertStringNotContainsString('https://', $text);
    }

    /**
     * @return array{0: User, 1: V2Organization}
     */
    private function fixtures(): array
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Ack Org',
            'slug' => 'ack-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $user->forceFill(['current_organization_id' => $org->id])->save();

        AiEmployeeSetting::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'enabled' => true,
            'autonomy_level' => 2,
            'meta' => [
                'business_profile' => [
                    'summary' => 'sales automation, lead generation, multichannel outreach, and meeting booking',
                    'website_url' => 'https://',
                ],
            ],
        ]);

        return [$user->fresh(), $org];
    }
}

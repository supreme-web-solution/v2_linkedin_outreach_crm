<?php

namespace Tests\Unit\V2\Ai;

use App\Models\AiEmployeeSetting;
use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLead;
use App\V2\Ai\Services\ConversionStageService;
use App\V2\Ai\Services\ProspectMemoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversionStageServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_advances_from_opening_to_qualifying_on_inbound(): void
    {
        [$user, $lead] = $this->userAndLead();

        $stages = app(ConversionStageService::class);
        $this->assertSame(ConversionStageService::STAGE_OPENING, $stages->current($lead));

        $next = $stages->advanceOnInbound($lead);
        $this->assertSame(ConversionStageService::STAGE_QUALIFYING, $next);
        $this->assertSame(
            ConversionStageService::STAGE_QUALIFYING,
            $stages->current($lead->fresh()),
        );
    }

    public function test_records_offered_asset_when_sales_page_sent(): void
    {
        [$user, $lead] = $this->userAndLead();

        AiEmployeeSetting::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'enabled' => true,
            'kill_switch' => false,
            'autonomy_level' => 2,
            'employee_name' => 'Soci',
            'meta' => [
                'conversion_assets' => [
                    'sales_page_url' => 'https://example.com/sales',
                ],
            ],
        ]);

        app(ProspectMemoryService::class)->setConversionStage($lead, ConversionStageService::STAGE_QUALIFYING);

        $stage = app(ConversionStageService::class)->recordOutbound(
            $lead,
            'Here is our sales page: https://example.com/sales',
            $user,
            (int) $user->current_organization_id,
        );

        $this->assertSame(ConversionStageService::STAGE_OFFERED_ASSET, $stage);
    }

    /**
     * @return array{0: User, 1: V2OutreachLead}
     */
    private function userAndLead(): array
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Stage Org',
            'slug' => 'stage-org-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $user->forceFill(['current_organization_id' => $org->id])->save();

        $campaign = V2OutreachCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'Stage Campaign',
            'status' => 'running',
            'node_model' => [],
        ]);

        $lead = V2OutreachLead::query()->create([
            'outreach_campaign_id' => $campaign->id,
            'full_name' => 'Alex Prospect',
            'status' => 'replied',
            'meta' => [],
        ]);

        return [$user, $lead];
    }
}

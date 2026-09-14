<?php

namespace Tests\Unit\V2\Ai;

use App\Models\AiActionApproval;
use App\Models\SnLead;
use App\Models\SnLeadList;
use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachImportLead;
use App\Models\V2OutreachImportList;
use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\DiscoverProspectsService;
use App\V2\Ai\Services\MultiChannelPlanLaunchService;
use App\V2\Ai\Services\PlatformAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiChannelPlanLaunchServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_launch_creates_one_campaign_per_discovered_channel(): void
    {
        [$user, $org] = $this->userWithOrg();

        $this->mock(PlatformAllocationService::class, function ($mock) {
            $mock->shouldReceive('searchableChannels')->andReturn(['instagram', 'linkedin']);
            $mock->shouldReceive('split')->andReturn(['instagram' => 20, 'linkedin' => 20]);
        });

        $this->mock(DiscoverProspectsService::class, function ($mock) {
            $mock->shouldReceive('discover')->twice()->andReturnUsing(function () {
                $args = func_get_args();
                $channel = (string) ($args[12] ?? 'linkedin');

                return [
                    'best_match' => [
                        'list_hash' => $channel.'_hash',
                        'list_src' => $channel === 'instagram' ? 'csv' : 'sn',
                        'list_name' => $channel.' ICP',
                        'total_leads' => 20,
                        'primary_channel' => $channel,
                        'platform' => $channel,
                    ],
                    'ready_for_campaign' => true,
                ];
            });
        });

        $this->seedChannelPeople($user, 'instagram', 'instagram_hash');
        $this->seedChannelPeople($user, 'linkedin', 'linkedin_hash');

        $approval = AiActionApproval::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'tool' => 'draft_campaign_plan',
            'permission' => AiToolPermission::Prepare->value,
            'status' => 'pending',
            'payload' => [
                'type' => 'campaign',
                'goal' => 'First experiment',
                'target_count' => 40,
                'preferred_channels' => 'Instagram + LinkedIn + Email',
                'channels' => 'Instagram + LinkedIn + Email',
                'discovery_channels' => ['instagram', 'linkedin'],
                'include_email' => true,
                'first_experiment' => true,
                'pause_on_reply' => true,
            ],
        ]);

        $result = app(MultiChannelPlanLaunchService::class)->launch($approval, $user);

        $this->assertCount(2, $result['campaign_ids']);
        $this->assertSame(2, V2OutreachCampaign::query()->count());
        $this->assertTrue(
            collect($result['lines'])->contains(fn ($line) => str_contains((string) $line, 'Instagram campaign'))
        );
        $this->assertTrue(
            collect($result['lines'])->contains(fn ($line) => str_contains((string) $line, 'LinkedIn campaign'))
        );
        $this->assertTrue(
            collect($result['lines'])->contains(fn ($line) => str_contains((string) $line, 'Email: enrichment'))
        );
    }

    public function test_launch_skips_a_channel_that_has_no_people(): void
    {
        [$user, $org] = $this->userWithOrg();

        $this->mock(PlatformAllocationService::class, function ($mock) {
            $mock->shouldReceive('searchableChannels')->andReturn(['instagram', 'linkedin']);
            $mock->shouldReceive('split')->andReturn(['instagram' => 20, 'linkedin' => 20]);
        });

        $this->mock(DiscoverProspectsService::class, function ($mock) {
            $mock->shouldReceive('discover')->twice()->andReturnUsing(function () {
                $args = func_get_args();
                $channel = (string) ($args[12] ?? 'linkedin');

                return [
                    'best_match' => [
                        'list_hash' => $channel.'_empty',
                        'list_src' => $channel === 'instagram' ? 'csv' : 'sn',
                        'list_name' => $channel.' empty',
                        'total_leads' => 20,
                        'primary_channel' => $channel,
                        'platform' => $channel,
                    ],
                    'ready_for_campaign' => true,
                ];
            });
        });

        $this->seedChannelPeople($user, 'instagram', 'instagram_empty');

        $approval = AiActionApproval::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'tool' => 'draft_campaign_plan',
            'permission' => AiToolPermission::Prepare->value,
            'status' => 'pending',
            'payload' => [
                'type' => 'campaign',
                'goal' => 'First experiment',
                'target_count' => 40,
                'preferred_channels' => 'Instagram + LinkedIn',
                'discovery_channels' => ['instagram', 'linkedin'],
            ],
        ]);

        $result = app(MultiChannelPlanLaunchService::class)->launch($approval, $user);

        $this->assertCount(1, $result['campaign_ids']);
        $this->assertSame(1, V2OutreachCampaign::query()->count());
        $this->assertTrue(
            collect($result['lines'])->contains(fn ($line) => str_contains((string) $line, 'empty'))
        );
    }

    private function seedChannelPeople(User $user, string $channel, string $hash): void
    {
        if (in_array($channel, ['instagram', 'twitter', 'email', 'whatsapp', 'telegram'], true)) {
            $list = V2OutreachImportList::query()->create([
                'user_id' => $user->id,
                'list_hash' => $hash,
                'name' => $channel.' people',
                'lead_count' => 1,
            ]);
            V2OutreachImportLead::query()->create([
                'import_list_id' => $list->id,
                'full_name' => ucfirst($channel).' Person',
                'email' => $channel.'@example.com',
                'phone' => '+15550001',
                'instagram_handle' => $channel === 'instagram' ? 'igperson' : null,
                'telegram_handle' => $channel === 'telegram' ? 'tgperson' : null,
                'twitter_handle' => $channel === 'twitter' ? 'xperson' : null,
                'whatsapp_provider_id' => $channel === 'whatsapp' ? '15550001' : null,
            ]);

            return;
        }

        SnLeadList::query()->create([
            'user_id' => $user->id,
            'list_hash' => $hash,
            'name' => 'LinkedIn people',
        ]);
        SnLead::query()->create([
            'sn_list_id' => $hash,
            'first_name' => 'Ada',
            'last_name' => 'Okon',
            'lid' => 'lid-'.$hash,
        ]);
    }

    /**
     * @return array{0: User, 1: V2Organization}
     */
    private function userWithOrg(): array
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Launch Org',
            'slug' => 'launch-'.uniqid(),
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

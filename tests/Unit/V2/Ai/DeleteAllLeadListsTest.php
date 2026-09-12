<?php

namespace Tests\Unit\V2\Ai;

use App\Models\Audience;
use App\Models\SnLeadList;
use App\Models\User;
use App\Models\V2OutreachImportList;
use App\V2\Ai\Services\DeleteCampaignCommandCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeleteAllLeadListsTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_deletable_lead_lists_includes_aud_sn_and_csv(): void
    {
        $user = User::factory()->create();

        Audience::query()->create([
            'user_id' => $user->id,
            'audience_id' => 'aud-del-1',
            'audience_name' => 'Audience one',
        ]);

        SnLeadList::query()->create([
            'user_id' => $user->id,
            'list_hash' => 'sn-del-1',
            'name' => 'SN one',
        ]);

        V2OutreachImportList::query()->create([
            'user_id' => $user->id,
            'list_hash' => 'imp-del-1',
            'name' => 'Instagram DM — test',
            'lead_count' => 1,
        ]);

        $listed = app(DeleteCampaignCommandCenterService::class)->listDeletableLeadLists($user);

        $this->assertCount(3, $listed);
        $this->assertSame(
            ['aud', 'csv', 'sn'],
            collect($listed)->pluck('list_src')->sort()->values()->all(),
        );
        $this->assertSame('lead_list', $listed[0]['kind']);
    }
}

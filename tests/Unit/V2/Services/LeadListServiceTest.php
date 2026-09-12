<?php

namespace Tests\Unit\V2\Services;

use App\Models\Audience;
use App\Models\AudienceList;
use App\Models\SnLead;
use App\Models\SnLeadList;
use App\Models\User;
use App\Models\V2OutreachImportLead;
use App\Models\V2OutreachImportList;
use App\V2\Services\LeadListService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadListServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_for_user_includes_csv_import_lists(): void
    {
        $user = User::factory()->create();

        $aud = Audience::query()->create([
            'user_id' => $user->id,
            'audience_id' => 'aud-1',
            'audience_name' => 'Audience A',
        ]);
        AudienceList::query()->create([
            'audience_id' => $aud->audience_id,
            'con_first_name' => 'Ada',
            'con_email' => 'ada@example.com',
        ]);

        $snList = SnLeadList::query()->create([
            'user_id' => $user->id,
            'list_hash' => 'sn-1',
            'name' => 'SN List',
        ]);
        SnLead::query()->create([
            'sn_list_id' => $snList->list_hash,
            'first_name' => 'Ben',
            'email' => 'ben@example.com',
            'lid' => 'ben-lid',
        ]);

        $csv = V2OutreachImportList::query()->create([
            'user_id' => $user->id,
            'list_hash' => 'csv-1',
            'name' => 'Imported CSV',
            'lead_count' => 0,
        ]);
        V2OutreachImportLead::query()->create([
            'import_list_id' => $csv->id,
            'full_name' => 'Cara C',
            'email' => 'cara@example.com',
            'phone' => '+123456',
        ]);

        $lists = app(LeadListService::class)->listsForUser($user->id);

        $this->assertTrue($lists->contains(fn (array $l) => $l['src'] === 'csv' && $l['list_id'] === 'csv-1'));
        $csvRow = $lists->first(fn (array $l) => $l['src'] === 'csv');
        $this->assertSame(1, (int) ($csvRow['total_leads'] ?? 0));
    }

    public function test_audience_lists_with_ig_prefix_stay_audience_not_instagram(): void
    {
        $user = User::factory()->create();

        Audience::query()->create([
            'user_id' => $user->id,
            'audience_id' => 'aud-li-mislabel',
            'audience_name' => 'IG: prospects (10) (10)',
        ]);

        $row = app(LeadListService::class)->listsForUser($user->id)
            ->first(fn (array $l) => $l['list_id'] === 'aud-li-mislabel');

        $this->assertNotNull($row);
        $this->assertSame('aud', $row['src']);
        $this->assertSame('Audience', $row['source']);
        $this->assertArrayNotHasKey('channel', $row);
    }

    public function test_lists_for_user_marks_instagram_csv_channel(): void
    {
        $user = User::factory()->create();

        $csv = V2OutreachImportList::query()->create([
            'user_id' => $user->id,
            'list_hash' => 'csv-ig',
            'name' => 'Instagram contact — tester',
            'lead_count' => 1,
        ]);

        $row = app(LeadListService::class)->listsForUser($user->id)
            ->first(fn (array $l) => $l['list_id'] === $csv->list_hash);

        $this->assertNotNull($row);
        $this->assertSame('instagram', $row['channel']);
        $this->assertSame('Instagram', $row['source']);
    }

    public function test_resolve_leads_from_lists_keeps_non_linkedin_import_leads(): void
    {
        $user = User::factory()->create();
        $csv = V2OutreachImportList::query()->create([
            'user_id' => $user->id,
            'list_hash' => 'csv-keep',
            'name' => 'CSV Keep',
            'lead_count' => 0,
        ]);

        V2OutreachImportLead::query()->create([
            'import_list_id' => $csv->id,
            'full_name' => 'No LinkedIn Lead',
            'email' => 'nolinkedin@example.com',
            'phone' => '+999999',
            'linkedin_id' => null,
            'profile_url' => null,
        ]);

        $resolved = app(LeadListService::class)->resolveLeadsFromLists($user->id, [[
            'list_id' => 'csv-keep',
            'src' => 'csv',
            'select_all' => true,
        ]]);

        $this->assertCount(1, $resolved);
        $this->assertSame('csv', (string) $resolved->first()['source']);
        $this->assertSame('nolinkedin@example.com', (string) $resolved->first()['email']);
    }
}

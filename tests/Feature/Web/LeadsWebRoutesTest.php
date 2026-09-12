<?php

namespace Tests\Feature\Web;

use App\Models\SnLead;
use App\Models\SnLeadList;
use App\Models\User;
use App\Models\V2OutreachImportLead;
use App\Models\V2OutreachImportList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadsWebRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_leads_index_does_not_duplicate_csv_lists(): void
    {
        $user = User::factory()->create();

        V2OutreachImportList::query()->create([
            'user_id' => $user->id,
            'list_hash' => 'imp-testhash123456',
            'name' => 'WhatsApp prospects',
            'lead_count' => 2,
        ]);

        V2OutreachImportLead::query()->create([
            'import_list_id' => V2OutreachImportList::query()->where('list_hash', 'imp-testhash123456')->value('id'),
            'full_name' => 'Jane Doe',
            'phone' => '33612345678',
        ]);

        $response = $this->actingAs($user)->get('/leads');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('crm/Leads/Index')
            ->has('importLists', 1)
            ->has('lists', 0)
            ->where('importLists.0.list_name', 'WhatsApp prospects')
            ->where('stats.total_lists', 1)
            ->where('stats.import_lists', 1));
    }

    public function test_show_import_list_accepts_csv_src(): void
    {
        $user = User::factory()->create();

        $importList = V2OutreachImportList::query()->create([
            'user_id' => $user->id,
            'list_hash' => 'imp-testhash123456',
            'name' => 'WhatsApp prospects',
            'lead_count' => 1,
        ]);

        V2OutreachImportLead::query()->create([
            'import_list_id' => $importList->id,
            'full_name' => 'Jane Doe',
            'email' => 'jane@example.com',
        ]);

        $response = $this->actingAs($user)->get('/leads/imp-testhash123456?src=csv');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('crm/Leads/ImportShow')
            ->where('listName', 'WhatsApp prospects'));
    }

    public function test_delete_import_list_accepts_csv_src(): void
    {
        $user = User::factory()->create();

        V2OutreachImportList::query()->create([
            'user_id' => $user->id,
            'list_hash' => 'imp-testhash123456',
            'name' => 'WhatsApp prospects',
            'lead_count' => 0,
        ]);

        $response = $this->actingAs($user)->delete('/leads/lists/imp-testhash123456?src=csv');

        $response->assertRedirect(route('leads'));
        $this->assertDatabaseMissing('v2_outreach_import_lists', ['list_hash' => 'imp-testhash123456']);
    }

    public function test_show_sn_list(): void
    {
        $user = User::factory()->create();

        SnLeadList::query()->create([
            'user_id' => $user->id,
            'list_hash' => 'search-1-eleazar',
            'name' => 'Test SN list',
        ]);

        SnLead::query()->create([
            'sn_list_id' => 'search-1-eleazar',
            'first_name' => 'Eleazar',
            'email' => 'eleazar@example.com',
            'lid' => 'eleazar-lid',
        ]);

        $response = $this->actingAs($user)->get('/leads/search-1-eleazar?src=sn');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('crm/Leads/Show')
            ->where('listName', 'Test SN list'));
    }

    public function test_delete_sn_list(): void
    {
        $user = User::factory()->create();

        SnLeadList::query()->create([
            'user_id' => $user->id,
            'list_hash' => 'search-1-eleazar',
            'name' => 'Test SN list',
        ]);

        $response = $this->actingAs($user)->delete('/leads/lists/search-1-eleazar?src=sn');

        $response->assertRedirect(route('leads'));
        $this->assertDatabaseMissing('sn_leads_lists', ['list_hash' => 'search-1-eleazar']);
    }

    public function test_bulk_delete_lists(): void
    {
        $user = User::factory()->create();

        SnLeadList::query()->create([
            'user_id' => $user->id,
            'list_hash' => 'search-1-eleazar',
            'name' => 'Test SN list',
        ]);

        SnLead::query()->create([
            'sn_list_id' => 'search-1-eleazar',
            'first_name' => 'Eleazar',
        ]);

        $response = $this->actingAs($user)->delete('/leads/lists/bulk', [
            'lists' => [
                ['list_hash' => 'search-1-eleazar', 'src' => 'sn'],
            ],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseMissing('sn_leads_lists', ['list_hash' => 'search-1-eleazar']);
    }
}

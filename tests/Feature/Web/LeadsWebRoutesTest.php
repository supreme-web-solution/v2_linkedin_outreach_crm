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

    public function test_leads_index_includes_import_lists(): void
    {
        $user = User::factory()->create();

        $importList = V2OutreachImportList::query()->create([
            'user_id' => $user->id,
            'list_hash' => 'imp-testhash123456',
            'name' => 'WhatsApp prospects',
            'lead_count' => 2,
        ]);

        V2OutreachImportLead::query()->create([
            'import_list_id' => $importList->id,
            'full_name' => 'Jane Doe',
            'phone' => '33612345678',
        ]);

        $response = $this->actingAs($user)->get('/leads');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('crm/Leads/Index')
            ->has('importLists', 1)
            ->where('importLists.0.list_name', 'WhatsApp prospects')
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

    public function test_show_list_accepts_string_list_hash(): void
    {
        $user = User::factory()->create();

        SnLeadList::query()->create([
            'name' => 'eleazar',
            'list_hash' => 'search-1-eleazar',
            'user_id' => $user->id,
        ]);

        SnLead::query()->create([
            'first_name' => 'Eleazar',
            'last_name' => 'Nzerem',
            'sn_list_id' => 'search-1-eleazar',
            'outreach_status' => 'new',
        ]);

        $response = $this->actingAs($user)->get('/leads/search-1-eleazar?src=sn');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('crm/Leads/Show')
            ->has('counts')
            ->where('counts.all', 1));
    }

    public function test_show_sn_list_applies_email_filter(): void
    {
        $user = User::factory()->create();

        SnLeadList::query()->create([
            'name' => 'SEO Agencies',
            'list_hash' => 'search-2-seo',
            'user_id' => $user->id,
        ]);

        SnLead::query()->create([
            'first_name' => 'With',
            'last_name' => 'Email',
            'sn_list_id' => 'search-2-seo',
            'email' => 'found@example.com',
            'outreach_status' => 'new',
        ]);

        SnLead::query()->create([
            'first_name' => 'No',
            'last_name' => 'Email',
            'sn_list_id' => 'search-2-seo',
            'email_fetch_status' => 'completed',
            'email_fetch_attempted_at' => now(),
            'outreach_status' => 'new',
        ]);

        $response = $this->actingAs($user)->get('/leads/search-2-seo?src=sn&email_filter=with_email');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('crm/Leads/Show')
            ->has('leads.data', 1)
            ->where('counts.with_email', 1)
            ->where('counts.without_email', 1));
    }

    public function test_delete_list_accepts_string_list_hash(): void
    {
        $user = User::factory()->create();

        SnLeadList::query()->create([
            'name' => 'eleazar',
            'list_hash' => 'search-1-eleazar',
            'user_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->delete('/leads/lists/search-1-eleazar?src=sn');

        $response->assertRedirect(route('leads'));
        $this->assertDatabaseMissing('sn_leads_lists', ['list_hash' => 'search-1-eleazar']);
    }

    public function test_update_sn_lead_status(): void
    {
        $user = User::factory()->create();

        SnLeadList::query()->create([
            'name' => 'eleazar',
            'list_hash' => 'search-1-eleazar',
            'user_id' => $user->id,
        ]);

        $lead = SnLead::query()->create([
            'first_name' => 'Eleazar',
            'last_name' => 'Nzerem',
            'sn_list_id' => 'search-1-eleazar',
            'outreach_status' => 'new',
        ]);

        $response = $this->actingAs($user)->patch("/leads/lead/{$lead->id}/status", [
            'src' => 'sn',
            'outreach_status' => 'contacted',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('sn_leads', [
            'id' => $lead->id,
            'outreach_status' => 'contacted',
        ]);
    }
}

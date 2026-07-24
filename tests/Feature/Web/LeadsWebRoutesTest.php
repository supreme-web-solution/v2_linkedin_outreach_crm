<?php

namespace Tests\Feature\Web;

use App\Models\SnLead;
use App\Models\SnLeadList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadsWebRoutesTest extends TestCase
{
    use RefreshDatabase;

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

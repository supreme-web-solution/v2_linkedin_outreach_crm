<?php

namespace Tests\Unit\V2\Ai;

use App\Models\User;
use App\Models\V2OutreachImportLead;
use App\Models\V2OutreachImportList;
use App\V2\Ai\Services\ProspectAudienceResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProspectAudienceResolverServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_enrich_attaches_existing_instagram_import_list_for_one_shot(): void
    {
        $user = User::factory()->create();
        $list = V2OutreachImportList::query()->create([
            'user_id' => $user->id,
            'name' => '@epaphrasio (1)',
            'list_hash' => 'ig-test-'.uniqid(),
            'lead_count' => 1,
        ]);
        V2OutreachImportLead::query()->create([
            'import_list_id' => $list->id,
            'full_name' => 'Epaphrasio',
            'instagram_handle' => 'epaphrasio',
        ]);

        $plan = app(ProspectAudienceResolverService::class)->enrichPlanWithAudience($user, [
            'goal' => 'Send a single Instagram introduction DM to epaphrasio',
            'audience' => '@epaphrasio',
            'preferred_channels' => 'Instagram',
            'channels' => 'Instagram',
            'one_shot' => true,
            'message' => 'Hi there',
        ]);

        $this->assertSame($list->list_hash, $plan['list_hash'] ?? null);
        $this->assertSame('csv', $plan['list_src'] ?? null);
        $this->assertSame('attached', $plan['audience_status'] ?? null);
    }

    public function test_enrich_extracts_instagram_url_from_goal_text(): void
    {
        $user = User::factory()->create();
        $list = V2OutreachImportList::query()->create([
            'user_id' => $user->id,
            'name' => '@epaphrasio (1)',
            'list_hash' => 'ig-url-'.uniqid(),
            'lead_count' => 1,
        ]);
        V2OutreachImportLead::query()->create([
            'import_list_id' => $list->id,
            'full_name' => 'Epaphrasio',
            'instagram_handle' => 'epaphrasio',
        ]);

        $plan = app(ProspectAudienceResolverService::class)->enrichPlanWithAudience($user, [
            'goal' => 'DM https://www.instagram.com/epaphrasio about software',
            'audience' => 'Epaphrasio',
            'preferred_channels' => 'Instagram',
            'channels' => 'Instagram',
            'one_shot' => true,
        ]);

        $this->assertSame($list->list_hash, $plan['list_hash'] ?? null);
        $this->assertSame('epaphrasio', $plan['instagram_handle'] ?? null);
    }

    public function test_resolve_finds_csv_import_list_by_hash(): void
    {
        $user = User::factory()->create();
        $list = V2OutreachImportList::query()->create([
            'user_id' => $user->id,
            'name' => 'Email lead',
            'list_hash' => 'csv-hash-'.uniqid(),
            'lead_count' => 1,
        ]);

        $resolved = app(ProspectAudienceResolverService::class)->resolve($user, [
            'list_hash' => $list->list_hash,
            'list_src' => 'csv',
            'list_name' => $list->name,
        ]);

        $this->assertNotNull($resolved);
        $this->assertSame(1, $resolved['total_leads']);
        $this->assertSame('csv', $resolved['list_src']);
    }

    public function test_enrich_attaches_existing_email_contact_for_one_shot_email(): void
    {
        $user = User::factory()->create();
        $list = V2OutreachImportList::query()->create([
            'user_id' => $user->id,
            'name' => 'Email contacts',
            'list_hash' => 'email-test-'.uniqid(),
            'lead_count' => 1,
        ]);
        V2OutreachImportLead::query()->create([
            'import_list_id' => $list->id,
            'full_name' => 'John',
            'email' => 'john@acme.com',
        ]);

        $plan = app(ProspectAudienceResolverService::class)->enrichPlanWithAudience($user, [
            'goal' => 'Send one email to john@acme.com',
            'audience' => 'john@acme.com',
            'preferred_channels' => 'Email',
            'channels' => 'Email',
            'one_shot' => true,
            'message' => 'Hello John',
        ]);

        $this->assertSame($list->list_hash, $plan['list_hash'] ?? null);
        $this->assertSame('csv', $plan['list_src'] ?? null);
        $this->assertSame('attached', $plan['audience_status'] ?? null);
    }

    public function test_enrich_attaches_existing_phone_contact_for_whatsapp_one_shot(): void
    {
        $user = User::factory()->create();
        $list = V2OutreachImportList::query()->create([
            'user_id' => $user->id,
            'name' => 'Phone contacts',
            'list_hash' => 'phone-test-'.uniqid(),
            'lead_count' => 1,
        ]);
        V2OutreachImportLead::query()->create([
            'import_list_id' => $list->id,
            'full_name' => 'Kola',
            'phone' => '+2348011112222',
        ]);

        $plan = app(ProspectAudienceResolverService::class)->enrichPlanWithAudience($user, [
            'goal' => 'Send one WhatsApp message to +2348011112222',
            'audience' => '+2348011112222',
            'preferred_channels' => 'WhatsApp',
            'channels' => 'WhatsApp',
            'one_shot' => true,
            'message' => 'Hi there',
        ]);

        $this->assertSame($list->list_hash, $plan['list_hash'] ?? null);
        $this->assertSame('csv', $plan['list_src'] ?? null);
        $this->assertSame('attached', $plan['audience_status'] ?? null);
    }

    public function test_enrich_attaches_existing_telegram_handle_for_one_shot(): void
    {
        $user = User::factory()->create();
        $list = V2OutreachImportList::query()->create([
            'user_id' => $user->id,
            'name' => 'Telegram contacts',
            'list_hash' => 'tg-test-'.uniqid(),
            'lead_count' => 1,
        ]);
        V2OutreachImportLead::query()->create([
            'import_list_id' => $list->id,
            'full_name' => 'Lina',
            'telegram_handle' => 'linatech',
        ]);

        $plan = app(ProspectAudienceResolverService::class)->enrichPlanWithAudience($user, [
            'goal' => 'Send one Telegram message to @linatech',
            'audience' => '@linatech',
            'preferred_channels' => 'Telegram',
            'channels' => 'Telegram',
            'one_shot' => true,
            'message' => 'Hi Lina',
        ]);

        $this->assertSame($list->list_hash, $plan['list_hash'] ?? null);
        $this->assertSame('csv', $plan['list_src'] ?? null);
        $this->assertSame('attached', $plan['audience_status'] ?? null);
    }
}

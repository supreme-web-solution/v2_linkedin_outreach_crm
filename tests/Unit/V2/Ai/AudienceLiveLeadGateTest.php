<?php

namespace Tests\Unit\V2\Ai;

use App\Models\SnLead;
use App\Models\User;
use App\Models\V2Lead;
use App\Models\V2LeadSource;
use App\Models\V2OutreachImportLead;
use App\Models\V2OutreachImportList;
use App\V2\Ai\Services\LinkedInAudienceBuilderService;
use App\V2\Ai\Services\ProspectAudienceResolverService;
use App\V2\Integrations\Unipile\UnipileException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AudienceLiveLeadGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_count_ignores_empty_source_lists(): void
    {
        $user = User::factory()->create();
        $resolver = app(ProspectAudienceResolverService::class);

        $this->assertSame(0, $resolver->liveLeadCount($user, 'sn', 'missing-hash'));

        V2OutreachImportList::query()->create([
            'user_id' => $user->id,
            'list_hash' => 'csv-empty',
            'name' => 'IG: empty',
            'lead_count' => 12,
        ]);

        $this->assertSame(0, $resolver->liveLeadCount($user, 'csv', 'csv-empty'));

        $liveCsv = V2OutreachImportList::query()->create([
            'user_id' => $user->id,
            'list_hash' => 'csv-whatsapp',
            'name' => 'WhatsApp imports',
            'lead_count' => 99,
        ]);
        V2OutreachImportLead::query()->create([
            'import_list_id' => $liveCsv->id,
            'full_name' => 'Ada',
            'phone' => '+15550001',
            'whatsapp_provider_id' => '15550001',
        ]);

        $this->assertSame(1, $resolver->liveLeadCount($user, 'csv', 'csv-whatsapp'));
    }

    public function test_linkedin_reuse_restores_sn_leads_from_v2_sources(): void
    {
        $user = User::factory()->create();
        $hash = 'search-'.$user->id.'-'.now()->format('YmdHis').'abcd';

        $lead = V2Lead::query()->create([
            'user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_profile_id' => 'ACoTestRestore1',
            'public_identifier' => 'restore-one',
            'full_name' => 'Ada Okon',
        ]);
        V2LeadSource::query()->create([
            'lead_id' => $lead->id,
            'source_type' => 'sales_navigator',
            'source_external_id' => $hash,
            'source_payload' => ['source_name' => 'LinkedIn Search'],
        ]);

        $this->assertSame(0, SnLead::query()->where('sn_list_id', $hash)->count());

        $reused = app(LinkedInAudienceBuilderService::class)->tryBuildFromPlan($user, 1, [
            'goal' => 'first experiment',
            'target_count' => 1,
        ]);

        $this->assertNotNull($reused);
        $this->assertSame($hash, $reused['list_hash']);
        $this->assertSame(1, (int) $reused['total_leads']);
        $this->assertSame(1, SnLead::query()->where('sn_list_id', $hash)->count());
    }

    public function test_linkedin_reuse_skips_when_sources_have_no_people(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $hash = 'search-'.$user->id.'-'.now()->format('YmdHis').'zzzz';

        $lead = V2Lead::query()->create([
            'user_id' => $other->id,
            'provider' => 'linkedin',
            'provider_profile_id' => 'ACoOtherUser',
            'public_identifier' => 'other-user',
            'full_name' => 'Other Person',
        ]);
        V2LeadSource::query()->create([
            'lead_id' => $lead->id,
            'source_type' => 'sales_navigator',
            'source_external_id' => $hash,
            'source_payload' => ['source_name' => 'Ghost list'],
        ]);

        $reused = app(LinkedInAudienceBuilderService::class)->tryBuildFromPlan($user, 1, [
            'goal' => 'first experiment',
            'target_count' => 1,
        ]);

        $this->assertNull($reused);
        $this->assertSame(0, SnLead::query()->where('sn_list_id', $hash)->count());
    }

    public function test_unipile_disconnected_account_is_detected(): void
    {
        $e = new UnipileException(
            'Messaging error (HTTP 401): The account appears to be disconnected from the provider service.',
            401,
            [
                'error_code' => 'errors/disconnected_account',
                'response' => ['type' => 'errors/disconnected_account'],
            ],
        );

        $this->assertTrue($e->isDisconnectedAccount());
        $this->assertFalse((new UnipileException('bad key', 401, []))->isDisconnectedAccount());
    }
}

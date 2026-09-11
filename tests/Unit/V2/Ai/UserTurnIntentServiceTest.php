<?php

namespace Tests\Unit\V2\Ai;

use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Ai\Services\CommandCenterService;
use App\V2\Ai\Services\UserTurnIntentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserTurnIntentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_informational_questions_do_not_trigger_acquisition(): void
    {
        $intent = app(UserTurnIntentService::class);

        $this->assertTrue($intent->isInformational('what do we have today'));
        $this->assertTrue($intent->isInformational('catch me up'));
        $this->assertTrue($intent->isInformational('how are things going?'));
        $this->assertFalse($intent->isInformational('get me 20 clients for my business'));
    }

    public function test_acquisition_commands_are_detected(): void
    {
        $intent = app(UserTurnIntentService::class);

        $this->assertTrue($intent->isProspectDiscoveryRequest('get me 20 clients'));
        $this->assertTrue($intent->isProspectDiscoveryRequest('find prospects in Lagos'));
        $this->assertFalse($intent->isProspectDiscoveryRequest('what do we have today'));
    }

    public function test_find_only_vs_outreach_intent(): void
    {
        $intent = app(UserTurnIntentService::class);

        $this->assertTrue($intent->isDiscoveryOnly('get me 20 customers'));
        $this->assertTrue($intent->isDiscoveryOnly('find 10 leads for my business'));
        $this->assertFalse($intent->isDiscoveryOnly('get me 20 clients and start outreach'));
        $this->assertTrue($intent->isOutreachCommand('launch a campaign for these leads'));
        $this->assertTrue($intent->isOutreachCommand('find 10 leads and message them'));
    }

    public function test_fresh_pull_intent_detects_more_and_do_not_reuse(): void
    {
        $intent = app(UserTurnIntentService::class);

        $this->assertTrue($intent->wantsFreshProspectPull('alright get me more 50 prospect of my business'));
        $this->assertTrue($intent->wantsFreshProspectPull('no pull fresh list for me , just 50 more, dont resue the saved ones'));
        $this->assertTrue($intent->wantsFreshProspectPull('another 25 leads please'));
        $this->assertFalse($intent->wantsFreshProspectPull('what do we have today'));
    }

    public function test_setup_only_campaign_intent_blocks_auto_launch(): void
    {
        $intent = app(UserTurnIntentService::class);

        $prompt = 'i want to reach out to these leads "IG: custom software solutions, AI integration, digital transformation, operation (10)", create the campaign but dont send it yet';

        $this->assertTrue($intent->isOutreachCommand($prompt));
        $this->assertTrue($intent->wantsCampaignSetupOnly($prompt));
        $this->assertFalse($intent->wantsCampaignSetupOnly('launch a campaign for these leads now'));
        $this->assertTrue($intent->wantsCampaignSetupOnly('create the outreach sequence but do not send yet'));
    }

    public function test_company_mention_without_outreach_verb_stays_non_outreach(): void
    {
        $intent = app(UserTurnIntentService::class);

        $this->assertFalse($intent->isOutreachCommand('Acme Digital in Lagos'));
        $this->assertFalse($intent->isProspectDiscoveryRequest('Acme Digital in Lagos'));
        $this->assertTrue($intent->isProspectDiscoveryRequest('find people at Acme Digital in Lagos'));
        $this->assertTrue($intent->isDiscoveryOnly('find people at Acme Digital in Lagos'));
    }

    public function test_find_prospect_details_is_linkedin_only_discovery_not_outreach(): void
    {
        $intent = app(UserTurnIntentService::class);

        $this->assertFalse($intent->isOutreachCommand('find prospect details'));
        $this->assertTrue($intent->prefersLinkedInOnlyDiscovery('find prospect details'));
        $this->assertFalse($intent->wantsInstagramDiscovery('find prospect details'));
        $this->assertTrue($intent->wantsInstagramDiscovery('find Instagram leads for coffee brands'));
        $this->assertTrue($intent->wantsInstagramDiscovery('search all channels for SaaS founders'));
    }

    public function test_what_do_we_have_today_returns_sync_brief_without_agent(): void
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Brief Org',
            'slug' => 'brief-org-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $result = app(CommandCenterService::class)->handleControlCommand(
            $user,
            $org->id,
            'what do we have today',
        );

        $this->assertTrue($result['handled'] ?? false);
        $this->assertSame('status_brief', $result['decision'] ?? null);
        $this->assertStringContainsString("Here's where things stand", (string) ($result['reply'] ?? ''));
        $this->assertStringNotContainsString('discover', strtolower((string) ($result['reply'] ?? '')));
    }
}

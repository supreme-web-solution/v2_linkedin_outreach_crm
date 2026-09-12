<?php

namespace Tests\Unit\V2\Ai;

use App\V2\Ai\Services\IntentGoalResolverService;
use Tests\TestCase;

class IntentGoalResolverServiceTest extends TestCase
{
    public function test_find_only_maps_to_semantic_discovery_contract(): void
    {
        $plan = app(IntentGoalResolverService::class)->resolve('find prospect details');

        $this->assertSame('discovery', $plan['goal']);
        $this->assertSame('find_only', $plan['required_outcome']);
        $this->assertSame('mutate_allowed', $plan['side_effect_budget']);
        $this->assertSame('find_and_save', $plan['desired_operation']);
        $this->assertIsArray($plan['objective']);
        $this->assertIsArray($plan['constraints']);
    }

    public function test_setup_only_maps_to_prepare_budget(): void
    {
        $plan = app(IntentGoalResolverService::class)->resolve('create campaign but do not send yet');

        $this->assertSame('outreach', $plan['goal']);
        $this->assertSame('setup_only', $plan['required_outcome']);
        $this->assertSame('prepare_only', $plan['side_effect_budget']);
    }

    public function test_outreach_maps_to_external_budget(): void
    {
        $plan = app(IntentGoalResolverService::class)->resolve('find 20 and reach out to them now');

        $this->assertSame('outreach', $plan['goal']);
        $this->assertSame('send_now', $plan['required_outcome']);
        $this->assertSame('external_send_allowed', $plan['side_effect_budget']);
    }

    public function test_delete_campaigns_maps_to_destructive_budget(): void
    {
        $plan = app(IntentGoalResolverService::class)->resolve('delete campaigns created today');

        $this->assertSame('management', $plan['goal']);
        $this->assertSame('delete_now', $plan['required_outcome']);
        $this->assertSame('destructive_allowed', $plan['side_effect_budget']);
    }

    public function test_delete_leads_maps_to_destructive_budget(): void
    {
        $plan = app(IntentGoalResolverService::class)->resolve('help delete all the leads i have');

        $this->assertSame('management', $plan['goal']);
        $this->assertSame('delete_now', $plan['required_outcome']);
        $this->assertSame('destructive_allowed', $plan['side_effect_budget']);
    }

    public function test_pause_campaign_maps_to_external_budget(): void
    {
        $plan = app(IntentGoalResolverService::class)->resolve('pause all campaigns now');

        $this->assertSame('management', $plan['goal']);
        $this->assertSame('execute_now', $plan['required_outcome']);
        $this->assertSame('external_send_allowed', $plan['side_effect_budget']);
    }

    public function test_delete_typo_still_maps_to_destructive_budget(): void
    {
        $plan = app(IntentGoalResolverService::class)->resolve('delet all leads now');

        $this->assertSame('delete_now', $plan['required_outcome']);
        $this->assertSame('destructive_allowed', $plan['side_effect_budget']);
    }

    public function test_semantic_variants_keep_same_general_goal(): void
    {
        $phrases = [
            'find 50 saas prospects',
            'i need 50 saas prospects',
            'identify 50 founder prospects',
            'get me a list of 50 startup prospects',
        ];

        foreach ($phrases as $phrase) {
            $plan = app(IntentGoalResolverService::class)->resolve($phrase);
            $this->assertNotSame('send_now', $plan['required_outcome']);
            $this->assertNotSame('delete_now', $plan['required_outcome']);
        }
    }
}

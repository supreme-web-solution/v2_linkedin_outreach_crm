<?php

namespace Tests\Unit\V2\Ai;

use App\V2\Ai\Services\IntentGoalResolverService;
use Tests\TestCase;

class IntentGoalResolverServiceTest extends TestCase
{
    public function test_find_only_maps_to_read_only_budget(): void
    {
        $plan = app(IntentGoalResolverService::class)->resolve('find prospect details');

        $this->assertSame('discovery', $plan['goal']);
        $this->assertSame('find_only', $plan['required_outcome']);
        $this->assertSame('read_only', $plan['side_effect_budget']);
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
}

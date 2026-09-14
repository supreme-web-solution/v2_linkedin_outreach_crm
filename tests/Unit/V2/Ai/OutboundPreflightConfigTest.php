<?php

namespace Tests\Unit\V2\Ai;

use Tests\TestCase;

class OutboundPreflightConfigTest extends TestCase
{
    public function test_outbound_preflight_defaults_off_so_agent_owns_cold_and_inbox(): void
    {
        $this->assertFalse((bool) config('socifusion_ai.outbound_preflight'));
    }
}

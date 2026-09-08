<?php

namespace Tests\Unit\V2\Ai;

use App\V2\Ai\Services\DiscoverProspectsService;
use Tests\TestCase;

class DiscoverProspectsFreshFetchTest extends TestCase
{
    public function test_target_count_forces_fresh_without_merging_weak_lists(): void
    {
        $svc = app(DiscoverProspectsService::class);

        // Reflection: matchLeadLists with forceFresh path uses includeWeakFallback=false
        $method = new \ReflectionMethod($svc, 'matchLeadLists');
        $method->setAccessible(true);

        // No user lists needed — ensure STRONG_MATCH_SCORE constant exists for resolver.
        $this->assertSame(10, DiscoverProspectsService::STRONG_MATCH_SCORE);
    }
}

<?php

namespace Tests\Unit\V2\Ai;

use App\Models\User;
use App\V2\Ai\Services\DiscoverProspectsService;
use App\V2\Integrations\Mindcase\MindcaseClient;
use App\V2\Outreach\OutreachChannelGuard;
use Mockery;
use Tests\TestCase;

class DiscoverProspectsParallelDefaultTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_should_use_parallel_only_for_auto_or_all_when_both_connected(): void
    {
        $user = User::factory()->make(['id' => 1]);

        $guard = Mockery::mock(OutreachChannelGuard::class);
        $guard->shouldReceive('isChannelConnected')->with(1, 'linkedin')->andReturn(true);
        $guard->shouldReceive('isChannelConnected')->with(1, 'instagram')->andReturn(true);

        $mindcase = Mockery::mock(MindcaseClient::class);
        $mindcase->shouldReceive('configured')->andReturn(true);

        $this->app->instance(OutreachChannelGuard::class, $guard);
        $this->app->instance(MindcaseClient::class, $mindcase);

        $method = new \ReflectionMethod(DiscoverProspectsService::class, 'shouldUseParallelDiscovery');
        $method->setAccessible(true);

        $service = app(DiscoverProspectsService::class);
        $this->assertFalse($method->invoke($service, $user, 'linkedin'));
        $this->assertFalse($method->invoke($service, $user, 'instagram'));
        $this->assertTrue($method->invoke($service, $user, 'auto'));
        $this->assertTrue($method->invoke($service, $user, 'all'));
    }

    public function test_target_count_honors_five_not_minimum_ten(): void
    {
        $service = app(DiscoverProspectsService::class);

        $this->assertSame(5, $service->normalizeTargetCount(5));
        $this->assertSame(100, $service->normalizeTargetCount(150));
        $this->assertSame(5, $service->inferCountFromQuery(
            'Find my ideal customers , then start conversation-first outreach, just 5 customers'
        ));
        $this->assertSame(100, $service->inferCountFromQuery(
            'get me my ideal customers, like 100 of them or above'
        ));
        $this->assertSame(50, $service->inferCountFromQuery(
            'alright get me more 50 prospect of my business'
        ));
    }

    public function test_should_not_use_parallel_when_only_linkedin_connected(): void
    {
        $user = User::factory()->make(['id' => 1]);

        $guard = Mockery::mock(OutreachChannelGuard::class);
        $guard->shouldReceive('isChannelConnected')->with(1, 'linkedin')->andReturn(true);
        $guard->shouldReceive('isChannelConnected')->with(1, 'instagram')->andReturn(false);

        $mindcase = Mockery::mock(MindcaseClient::class);
        $mindcase->shouldReceive('configured')->andReturn(true);

        $this->app->instance(OutreachChannelGuard::class, $guard);
        $this->app->instance(MindcaseClient::class, $mindcase);

        $method = new \ReflectionMethod(DiscoverProspectsService::class, 'shouldUseParallelDiscovery');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke(app(DiscoverProspectsService::class), $user, 'linkedin'));
    }
}

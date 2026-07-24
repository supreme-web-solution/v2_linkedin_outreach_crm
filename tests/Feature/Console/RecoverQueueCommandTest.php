<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecoverQueueCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']);
    }

    public function test_releases_stale_reserved_jobs(): void
    {
        DB::table('jobs')->insert([
            'queue' => 'webhooks',
            'payload' => '{}',
            'attempts' => 1,
            'reserved_at' => now()->subMinutes(10)->getTimestamp(),
            'available_at' => now()->getTimestamp(),
            'created_at' => now()->getTimestamp(),
        ]);

        $this->artisan('queue:recover --release-stale --minutes=5')
            ->assertSuccessful();

        $this->assertSame(1, DB::table('jobs')->whereNull('reserved_at')->count());
    }

    public function test_reports_queue_stats(): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->getTimestamp(),
            'created_at' => now()->getTimestamp(),
        ]);

        $this->artisan('queue:recover --release-stale')
            ->expectsOutputToContain('Pending jobs')
            ->assertSuccessful();
    }
}

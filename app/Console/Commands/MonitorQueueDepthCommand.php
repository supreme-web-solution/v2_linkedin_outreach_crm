<?php

namespace App\Console\Commands;

use App\V2\Services\OpsAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

class MonitorQueueDepthCommand extends Command
{
    protected $signature = 'queue:monitor-depth
        {--threshold= : Override pending-depth alert threshold}';

    protected $description = 'Report Redis queue depths and alert when pending jobs exceed threshold';

    public function handle(): int
    {
        $driver = (string) config('queue.default');
        $threshold = max(1, (int) ($this->option('threshold') ?: config('services.ops.alert_queue_depth_threshold', 200)));
        $queues = config('services.ops.monitored_queues', [
            'outreach',
            'campaigns',
            'webhooks',
            'default',
            'enrichment',
        ]);
        if (! is_array($queues) || $queues === []) {
            $queues = ['default'];
        }

        $depths = [];
        if ($driver === 'redis') {
            try {
                foreach ($queues as $queue) {
                    $queue = trim((string) $queue);
                    if ($queue === '') {
                        continue;
                    }
                    $depths[$queue] = $this->redisQueueDepth($queue);
                }
            } catch (\Throwable $e) {
                $this->error('Redis queue depth probe failed: '.$e->getMessage());
                app(OpsAlertService::class)->queueHealth(
                    'Redis queue depth probe failed',
                    ['error' => $e->getMessage()],
                );

                return self::FAILURE;
            }
        } elseif ($driver === 'database' && Schema::hasTable('jobs')) {
            $pending = (int) DB::table('jobs')->whereNull('reserved_at')->count();
            $depths['database'] = $pending;
        } else {
            $this->warn("Queue driver [{$driver}] has no depth probe; checking failed_jobs only.");
        }

        $failed = Schema::hasTable('failed_jobs')
            ? (int) DB::table('failed_jobs')->count()
            : 0;
        $failedThreshold = (int) config('services.ops.alert_failed_jobs_threshold', 10);

        $rows = [];
        foreach ($depths as $queue => $count) {
            $rows[] = [$queue, $count, $count >= $threshold ? 'ALERT' : 'ok'];
        }
        $rows[] = ['failed_jobs', $failed, ($failedThreshold > 0 && $failed >= $failedThreshold) ? 'ALERT' : 'ok'];

        $this->table(['Queue / metric', 'Count', 'Status'], $rows);

        $hot = [];
        foreach ($depths as $queue => $count) {
            if ($count >= $threshold) {
                $hot[$queue] = $count;
            }
        }

        if ($hot !== []) {
            $summary = collect($hot)
                ->map(fn (int $count, string $queue) => "{$queue}={$count}")
                ->implode(', ');

            app(OpsAlertService::class)->queueHealth(
                "Queue depth alert (threshold {$threshold}): {$summary}",
                array_merge(['threshold' => $threshold], $hot),
            );
        }

        if ($failedThreshold > 0 && $failed >= $failedThreshold) {
            app(OpsAlertService::class)->queueHealth(
                "Failed job count is {$failed} (threshold {$failedThreshold})",
                ['failed_jobs' => $failed, 'threshold' => $failedThreshold],
            );
        }

        return self::SUCCESS;
    }

    private function redisQueueDepth(string $queue): int
    {
        $connection = (string) config('queue.connections.redis.connection', 'default');
        $redis = Redis::connection($connection);

        $pending = (int) $redis->llen("queues:{$queue}");
        $delayed = (int) $redis->zcard("queues:{$queue}:delayed");
        $reserved = (int) $redis->zcard("queues:{$queue}:reserved");

        // Pending + delayed is the backlog operators care about; reserved is in-flight.
        return $pending + $delayed + $reserved;
    }
}

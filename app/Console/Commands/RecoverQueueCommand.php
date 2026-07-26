<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\V2\Services\OpsAlertService;

class RecoverQueueCommand extends Command
{
    protected $signature = 'queue:recover
        {--release-stale : Release jobs stuck in reserved state}
        {--retry-failed : Retry recent failed jobs}
        {--minutes= : Minutes before a reserved job is considered stale (defaults to queue retry_after)}
        {--failed-limit=25 : Maximum failed jobs to retry in one run}';

    protected $description = 'Recover queue health: release stale reserved jobs and optionally retry failed jobs';

    public function handle(): int
    {
        if (config('queue.default') === 'sync') {
            $this->warn('Queue driver is sync — nothing to recover.');

            return self::SUCCESS;
        }

        if (! Schema::hasTable('jobs')) {
            $this->warn('Jobs table not found. Run migrations first.');

            return self::FAILURE;
        }

        $this->printQueueStats();
        $this->warnAboutUnsafeProductionStack();

        $released = 0;
        if ($this->option('release-stale') || ! $this->option('retry-failed')) {
            $released = $this->releaseStaleReservedJobs();
            if ($released > 0) {
                $this->info("Released {$released} stale reserved job(s) back to the queue.");
            } else {
                $this->line('No stale reserved jobs found.');
            }
        }

        $retried = 0;
        if ($this->option('retry-failed')) {
            $retried = $this->retryRecentFailedJobs((int) $this->option('failed-limit'));
            if ($retried > 0) {
                $this->info("Retried {$retried} failed job(s).");
            } else {
                $this->line('No failed jobs to retry.');
            }
        }

        $this->newLine();
        $this->printQueueStats();

        $failed = Schema::hasTable('failed_jobs')
            ? (int) DB::table('failed_jobs')->count()
            : 0;
        $threshold = (int) config('services.ops.alert_failed_jobs_threshold', 10);

        if ($released > 0) {
            app(OpsAlertService::class)->queueHealth(
                "Released {$released} stale reserved queue job(s)",
                ['released' => $released],
            );
        }

        if ($failed >= $threshold && $threshold > 0) {
            app(OpsAlertService::class)->queueHealth(
                "Failed job count is {$failed} (threshold {$threshold})",
                ['failed_jobs' => $failed, 'threshold' => $threshold],
            );
        }

        if ($released === 0 && $retried === 0 && ! $this->option('retry-failed')) {
            $this->comment('Tip: run with --retry-failed to re-queue recent failures.');
        }

        return self::SUCCESS;
    }

    private function printQueueStats(): void
    {
        $pending = (int) DB::table('jobs')->whereNull('reserved_at')->count();
        $reserved = (int) DB::table('jobs')->whereNotNull('reserved_at')->count();
        $failed = Schema::hasTable('failed_jobs')
            ? (int) DB::table('failed_jobs')->count()
            : 0;

        $this->table(
            ['Metric', 'Count'],
            [
                ['Pending jobs', $pending],
                ['Reserved (in-flight)', $reserved],
                ['Failed jobs', $failed],
            ],
        );
    }

    private function releaseStaleReservedJobs(): int
    {
        $retryAfter = (int) ($this->option('minutes') ?: config('queue.connections.database.retry_after', 90));
        $cutoff = now()->subSeconds($retryAfter)->getTimestamp();

        $staleIds = DB::table('jobs')
            ->whereNotNull('reserved_at')
            ->where('reserved_at', '<=', $cutoff)
            ->pluck('id');

        if ($staleIds->isEmpty()) {
            return 0;
        }

        return DB::table('jobs')
            ->whereIn('id', $staleIds)
            ->update([
                'reserved_at' => null,
            ]);
    }

    private function retryRecentFailedJobs(int $limit): int
    {
        if (! Schema::hasTable('failed_jobs') || $limit <= 0) {
            return 0;
        }

        $uuids = DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->limit($limit)
            ->pluck('uuid');

        $retried = 0;
        foreach ($uuids as $uuid) {
            $exitCode = Artisan::call('queue:retry', ['id' => [$uuid]]);
            if ($exitCode === self::SUCCESS) {
                $retried++;
            }
        }

        return $retried;
    }

    private function warnAboutUnsafeProductionStack(): void
    {
        $dbDriver = config('database.default');
        $queueDriver = config('queue.default');

        if ($dbDriver === 'sqlite' && $queueDriver === 'database') {
            $this->newLine();
            $this->warn('SQLite + database queue detected. This causes "database is locked" under concurrent web + worker load.');
            $this->line('  Local: enable WAL (DB_JOURNAL_MODE=wal) and run a single queue worker.');
            $this->line('  Production: use MySQL/PostgreSQL for DB and Redis for QUEUE_CONNECTION.');
        }
    }
}

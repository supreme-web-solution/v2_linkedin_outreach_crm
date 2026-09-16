<?php

namespace App\Console\Commands;

use App\V2\Campaign\CampaignConcurrencyLimiter;
use App\V2\Outreach\OutreachConcurrencyLimiter;
use App\V2\Services\OpsAlertService;
use App\V2\Support\QueueRefillFromDatabaseService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RecoverQueueCommand extends Command
{
    protected $signature = 'queue:recover
        {--release-stale : Release jobs stuck in reserved state (database queue only)}
        {--retry-failed : Retry recent failed jobs}
        {--refill : Refill Redis from durable MySQL schedules (default on for redis queues)}
        {--no-refill : Skip database→Redis refill}
        {--minutes= : Minutes before a reserved job is considered stale (defaults to queue retry_after)}
        {--failed-limit=25 : Maximum failed jobs to retry in one run}';

    protected $description = 'Recover queue health: free leases, optionally release stale DB jobs, and refill Redis from MySQL';

    public function handle(): int
    {
        if (config('queue.default') === 'sync') {
            $this->warn('Queue driver is sync — nothing to recover.');

            return self::SUCCESS;
        }

        $usesDatabaseJobs = Schema::hasTable('jobs') && config('queue.default') === 'database';

        if ($usesDatabaseJobs) {
            $this->printQueueStats();
        } else {
            $this->line('Queue driver: '.(string) config('queue.default').' (Redis/Horizon — durable refill uses MySQL schedules).');
        }

        $this->warnAboutUnsafeProductionStack();

        $released = 0;
        if ($usesDatabaseJobs && ($this->option('release-stale') || ! $this->option('retry-failed'))) {
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

        $inflightFreed = $this->recoverInFlightCounters();
        if ($inflightFreed > 0) {
            $this->info("Freed {$inflightFreed} expired in-flight concurrency lease(s).");
        }

        $shouldRefill = ! $this->option('no-refill')
            && ($this->option('refill') || config('queue.default') === 'redis' || ! $usesDatabaseJobs);

        if ($shouldRefill) {
            $summary = app(QueueRefillFromDatabaseService::class)->refill(100, false);
            $this->info(sprintf(
                'Refilled from DB — outreach:%d campaigns:%d posts:%d workflows:%d preparing:%d/%d',
                $summary['outreach_leads'],
                $summary['campaign_leads'],
                $summary['content_posts'],
                $summary['workflows'],
                $summary['preparing_outreach'],
                $summary['preparing_campaigns'],
            ));
        }

        if ($usesDatabaseJobs) {
            $this->newLine();
            $this->printQueueStats();
        }

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

        if ($released === 0 && $retried === 0 && ! $this->option('retry-failed') && ! $shouldRefill) {
            $this->comment('Tip: run with --retry-failed or queue:refill-from-db after a Redis wipe.');
        }

        return self::SUCCESS;
    }

    private function printQueueStats(): void
    {
        if (! Schema::hasTable('jobs')) {
            return;
        }

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
        if (! Schema::hasTable('jobs')) {
            return 0;
        }

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

    private function recoverInFlightCounters(): int
    {
        $outreach = app(OutreachConcurrencyLimiter::class)->recoverAll();
        $campaign = app(CampaignConcurrencyLimiter::class)->recoverAll();

        return (int) ($outreach['leases_freed'] ?? 0) + (int) ($campaign['leases_freed'] ?? 0);
    }
}

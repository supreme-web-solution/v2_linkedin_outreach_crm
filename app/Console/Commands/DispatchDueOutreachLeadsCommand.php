<?php

namespace App\Console\Commands;

use App\V2\Outreach\OutreachDueLeadDispatcher;
use Illuminate\Console\Command;

class DispatchDueOutreachLeadsCommand extends Command
{
    protected $signature = 'outreach:dispatch-due
        {--limit=100 : Max leads to wake per run}
        {--force : Bypass poll throttle and clear future next_run_at so deferred/condition steps retry now}';

    protected $description = 'Re-queue outreach leads waiting on daily caps, delays, Invite accepted?, or other conditions';

    public function handle(OutreachDueLeadDispatcher $dispatcher): int
    {
        $force = (bool) $this->option('force');
        $result = $dispatcher->dispatchDue((int) $this->option('limit'), $force);

        $this->info("Dispatched {$result['dispatched']} due outreach lead(s).");

        if ($result['dispatched'] === 0 && ! $force) {
            $this->comment('Nothing due right now (next_run_at still in the future, and/or 45m poll throttle).');
            $this->comment('To wake deferred Send Invite / condition steps now: php artisan outreach:dispatch-due --force');
        } elseif ($result['skipped_throttled'] > 0) {
            $this->comment("Skipped {$result['skipped_throttled']} lead(s) still inside the 45m poll throttle (use --force).");
        }

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\V2\Campaign\CampaignDueLeadDispatcher;
use Illuminate\Console\Command;

class DispatchDueCampaignLeadsCommand extends Command
{
    protected $signature = 'campaigns:dispatch-due
        {--limit=100 : Max leads to wake per run}
        {--force : Bypass poll throttle and clear future next_run_at so Invite Accepted? rechecks now}';

    protected $description = 'Re-queue classic campaign leads whose Invite Accepted? (or other) wait window has elapsed';

    public function handle(CampaignDueLeadDispatcher $dispatcher): int
    {
        $force = (bool) $this->option('force');
        $result = $dispatcher->dispatchDue((int) $this->option('limit'), $force);

        $this->info("Dispatched {$result['dispatched']} due campaign lead(s).");

        if ($result['dispatched'] === 0 && ! $force) {
            $this->comment('Nothing due right now (next_run_at still in the future, and/or 45m poll throttle).');
            $this->comment('To recheck Invite Accepted? immediately: php artisan campaigns:dispatch-due --force');
        } elseif ($result['skipped_throttled'] > 0) {
            $this->comment("Skipped {$result['skipped_throttled']} lead(s) still inside the 45m poll throttle (use --force).");
        }

        return self::SUCCESS;
    }
}

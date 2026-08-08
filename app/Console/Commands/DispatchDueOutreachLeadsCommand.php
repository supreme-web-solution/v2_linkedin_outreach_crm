<?php

namespace App\Console\Commands;

use App\V2\Outreach\OutreachDueLeadDispatcher;
use Illuminate\Console\Command;

class DispatchDueOutreachLeadsCommand extends Command
{
    protected $signature = 'outreach:dispatch-due {--limit=100 : Max leads to wake per run}';

    protected $description = 'Re-queue outreach leads waiting on Has replied? / Invite accepted? / other conditions';

    public function handle(OutreachDueLeadDispatcher $dispatcher): int
    {
        $result = $dispatcher->dispatchDue((int) $this->option('limit'));
        $this->info("Dispatched {$result['dispatched']} due outreach lead(s).");

        return self::SUCCESS;
    }
}

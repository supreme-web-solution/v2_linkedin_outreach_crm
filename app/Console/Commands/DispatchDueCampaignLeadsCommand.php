<?php

namespace App\Console\Commands;

use App\V2\Campaign\CampaignDueLeadDispatcher;
use Illuminate\Console\Command;

class DispatchDueCampaignLeadsCommand extends Command
{
    protected $signature = 'campaigns:dispatch-due {--limit=100 : Max leads to wake per run}';

    protected $description = 'Re-queue classic campaign leads whose Invite Accepted? (or other) wait window has elapsed';

    public function handle(CampaignDueLeadDispatcher $dispatcher): int
    {
        $result = $dispatcher->dispatchDue((int) $this->option('limit'));
        $this->info("Dispatched {$result['dispatched']} due campaign lead(s).");

        return self::SUCCESS;
    }
}

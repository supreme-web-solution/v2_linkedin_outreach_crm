<?php

namespace App\Console\Commands;

use App\V2\Support\QueueRefillFromDatabaseService;
use Illuminate\Console\Command;

class RefillQueueFromDatabaseCommand extends Command
{
    protected $signature = 'queue:refill-from-db
        {--limit=100 : Max items per durable source}
        {--force : Bypass poll throttles (wake deferred / condition waits now)}';

    protected $description = 'Refill Redis queues from durable MySQL schedules after Horizon/Redis loss (keeps Redis as the fast path)';

    public function handle(QueueRefillFromDatabaseService $refill): int
    {
        $summary = $refill->refill((int) $this->option('limit'), (bool) $this->option('force'));

        $this->info('Queue refill from database complete.');
        $this->table(
            ['Source', 'Dispatched'],
            [
                ['Outreach leads', $summary['outreach_leads']],
                ['Classic campaign leads', $summary['campaign_leads']],
                ['Call messages', $summary['call_messages']],
                ['Call reminders', $summary['call_reminders']],
                ['Scheduled content posts', $summary['content_posts']],
                ['Soci workflows', $summary['workflows']],
                ['Preparing outreach sync', $summary['preparing_outreach']],
                ['Preparing campaign sync', $summary['preparing_campaigns']],
                ['Concurrency leases freed', $summary['leases_freed']],
            ],
        );

        if (($summary['outreach_leads'] + $summary['campaign_leads'] + $summary['content_posts'] + $summary['workflows']) === 0) {
            $this->comment('Nothing due right now — Redis may already be in sync, or wake times are still in the future.');
            $this->comment('After horizon:terminate / Redis flush, run again in a minute or with --force.');
        }

        return self::SUCCESS;
    }
}

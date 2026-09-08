<?php

namespace App\Console\Commands;

use App\V2\Outreach\CampaignEmailEnrichmentWaveService;
use Illuminate\Console\Command;

class DrainOutreachEmailEnrichmentWavesCommand extends Command
{
    protected $signature = 'outreach:enrich-email-waves {--limit=40 : Max campaigns to scan}';

    protected $description = 'Queue next email enrichment waves (≤25, daily-capped) for running outreach campaigns that need email';

    public function handle(CampaignEmailEnrichmentWaveService $waves): int
    {
        $result = $waves->drainDueCampaigns((int) $this->option('limit'));
        $this->info("Scanned email-needed campaigns: {$result['campaigns']}; queued lookups: {$result['queued']}.");

        return self::SUCCESS;
    }
}

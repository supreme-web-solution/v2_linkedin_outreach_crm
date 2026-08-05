<?php

namespace App\Jobs\V2;

use App\V2\Support\DeletedCampaignArtifactCleaner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Drains queued work + orphaned logs after a campaign/outreach delete.
 * Runs on default so it does not compete with outreach/campaigns send workers.
 */
class CleanupDeletedCampaignArtifactsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    /**
     * @param  array<int, int>  $campaignRunIds
     */
    public function __construct(
        public readonly string $kind,
        public readonly int $deletedCampaignId,
        public readonly ?int $userId = null,
        public readonly array $campaignRunIds = [],
    ) {
        $this->onQueue('default');
    }

    public function handle(DeletedCampaignArtifactCleaner $cleaner): void
    {
        $cleaner->clean(
            $this->kind,
            $this->deletedCampaignId,
            $this->userId,
            $this->campaignRunIds,
        );
    }
}

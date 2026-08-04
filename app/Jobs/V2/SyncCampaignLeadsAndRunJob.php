<?php

namespace App\Jobs\V2;

use App\Models\V2Campaign;
use App\V2\Campaign\CampaignActivityLogger;
use App\V2\Campaign\CampaignLeadSyncService;
use App\V2\Campaign\CampaignRunDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One short sync page (≤250 rows) on the existing `default` queue — no new Horizon supervisor.
 */
class SyncCampaignLeadsAndRunJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly int $campaignId,
        public readonly ?int $organizationId = null,
        public readonly int $listIndex = 0,
        public readonly int $afterId = 0,
        public readonly int $addedSoFar = 0,
    ) {
        $this->onQueue('default');
    }

    public function handle(
        CampaignLeadSyncService $sync,
        CampaignRunDispatcher $dispatcher,
        CampaignActivityLogger $logger,
    ): void {
        $campaign = V2Campaign::query()->find($this->campaignId);
        if (! $campaign || ! in_array($campaign->status, ['preparing', 'running'], true)) {
            return;
        }

        try {
            $chunk = $sync->syncNextChunk($campaign, $this->listIndex, $this->afterId);
            $added = $this->addedSoFar + $chunk['added'];

            if (! $chunk['done']) {
                self::dispatch(
                    $this->campaignId,
                    $this->organizationId,
                    $chunk['list_index'],
                    $chunk['after_id'],
                    $added,
                );

                return;
            }

            $sync->markSyncComplete($campaign);

            $logger->log(
                $campaign->id,
                null,
                null,
                null,
                'info',
                "Lead sync finished — {$added} new lead(s). Starting run…",
            );

            $result = $dispatcher->dispatch($campaign->fresh(), $this->organizationId);

            if ($result['blocked'] ?? false) {
                $sync->markSyncFailed($campaign->fresh(), 'LinkedIn disconnected — reconnect before launching.');
            }
        } catch (Throwable $e) {
            Log::error('[Campaign] Lead sync chunk failed', [
                'campaign_id' => $this->campaignId,
                'list_index' => $this->listIndex,
                'after_id' => $this->afterId,
                'error' => $e->getMessage(),
            ]);
            $sync->markSyncFailed($campaign->fresh(), $e->getMessage());
            $logger->log(
                $campaign->id,
                null,
                null,
                null,
                'failed',
                'Lead sync failed: '.$e->getMessage(),
            );

            throw $e;
        }
    }
}

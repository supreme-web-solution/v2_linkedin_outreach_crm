<?php

namespace App\Jobs\V2;

use App\Models\V2OutreachCampaign;
use App\V2\Outreach\OutreachActivityLogger;
use App\V2\Outreach\OutreachLeadSyncService;
use App\V2\Outreach\OutreachRunDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One short sync page (≤250 rows) on the existing `default` queue — no new Horizon supervisor.
 * Chains until done, then starts the outreach run. Interleaves with outreach/campaigns work.
 */
class SyncOutreachLeadsAndRunJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly int $outreachCampaignId,
        public readonly ?int $organizationId = null,
        public readonly int $listIndex = 0,
        public readonly int $afterId = 0,
        public readonly int $addedSoFar = 0,
    ) {
        // Already on supervisor-main, last in queue order — won't starve outreach/campaigns/webhooks.
        $this->onQueue('default');
    }

    public function handle(
        OutreachLeadSyncService $sync,
        OutreachRunDispatcher $dispatcher,
        OutreachActivityLogger $logger,
    ): void {
        $campaign = V2OutreachCampaign::query()->find($this->outreachCampaignId);
        if (! $campaign || ! in_array($campaign->status, ['preparing', 'running'], true)) {
            return;
        }

        try {
            $chunk = $sync->syncNextChunk($campaign, $this->listIndex, $this->afterId);
            $added = $this->addedSoFar + $chunk['added'];

            if (! $chunk['done']) {
                self::dispatch(
                    $this->outreachCampaignId,
                    $this->organizationId,
                    $chunk['list_index'],
                    $chunk['after_id'],
                    $added,
                );

                return;
            }

            $sync->markSyncComplete($campaign, $added);

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
                $sync->markSyncFailed($campaign->fresh(), 'Required channels are not connected.');
            }
        } catch (Throwable $e) {
            Log::error('[Outreach] Lead sync chunk failed', [
                'campaign_id' => $this->outreachCampaignId,
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

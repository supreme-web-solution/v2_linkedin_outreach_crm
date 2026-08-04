<?php

namespace App\Jobs;

use App\Models\Audience;
use App\V2\Services\CompetitorEngagerHarvestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Processes one competitor post (reactions + comments), then chains the next.
 */
class HarvestCompetitorPostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        public int $userId,
        public int $audiencePkId,
        public int $postIndex,
        public string $companyUrl = '',
    ) {
        $this->onQueue('default');
    }

    public function handle(CompetitorEngagerHarvestService $harvest): void
    {
        $audience = Audience::find($this->audiencePkId);
        if (! $audience) {
            Log::warning('HarvestCompetitorPostJob: Audience not found', ['audiencePkId' => $this->audiencePkId]);

            return;
        }

        try {
            $result = $harvest->harvestPreparedPost($audience, $this->userId, $this->postIndex);

            if (! $result['done']) {
                self::dispatch(
                    $this->userId,
                    $this->audiencePkId,
                    $result['next_index'],
                    $this->companyUrl,
                );

                return;
            }

            $meta = json_decode((string) $audience->fresh()->source_meta, true) ?? [];
            $meta['fetch_status'] = 'completed';
            $meta['fetch_progress'] = sprintf(
                'Done — stored %d engager(s) from %d post(s).',
                $result['stored_count'],
                $result['posts_scanned']
            );
            $meta['fetch_completed_at'] = now()->toIso8601String();
            $meta['stored_count'] = $result['stored_count'];
            $meta['total_fetched'] = $result['total_fetched'];
            $meta['posts_scanned'] = $result['posts_scanned'];
            $meta['last_error'] = null;
            $meta['last_error_type'] = null;
            $audience->source_meta = json_encode($meta);
            $audience->save();

            Log::info('HarvestCompetitorPostJob: completed', [
                'audience_id' => $audience->audience_id,
                'company_url' => $this->companyUrl,
                'stored_count' => $result['stored_count'],
                'posts_scanned' => $result['posts_scanned'],
            ]);
        } catch (\Throwable $exception) {
            $meta = json_decode((string) $audience->fresh()->source_meta, true) ?? [];
            $meta['fetch_status'] = 'failed';
            $meta['fetch_progress'] = 'Harvest failed.';
            $meta['fetch_failed_at'] = now()->toIso8601String();
            $meta['last_error'] = $exception->getMessage();
            $meta['last_error_type'] = 'harvest_error';
            $meta['last_error_at'] = now()->toIso8601String();
            $audience->source_meta = json_encode($meta);
            $audience->save();

            Log::error('HarvestCompetitorPostJob: failed', [
                'audience_id' => $audience->audience_id,
                'post_index' => $this->postIndex,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}

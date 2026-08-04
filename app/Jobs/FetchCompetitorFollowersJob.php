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
 * Orchestrator: resolve company/posts, then chain per-post harvest jobs.
 */
class FetchCompetitorFollowersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(
        public int $userId,
        public int $audiencePkId,
        public string $companyUrl,
        public string $sessionCookie = '',
        public string $userAgent = '',
    ) {
        $this->onQueue('default');
    }

    public function handle(CompetitorEngagerHarvestService $harvest): void
    {
        $audience = Audience::find($this->audiencePkId);
        if (! $audience) {
            Log::warning('FetchCompetitorFollowersJob: Audience not found', ['audiencePkId' => $this->audiencePkId]);

            return;
        }

        $meta = json_decode((string) $audience->source_meta, true) ?? [];
        $meta['fetch_status'] = 'processing';
        $meta['fetch_progress'] = 'Starting Unipile harvest…';
        $meta['fetch_started_at'] = $meta['fetch_started_at'] ?? now()->toIso8601String();
        $meta['last_error'] = null;
        $meta['last_error_type'] = null;
        $audience->source_meta = json_encode($meta);
        $audience->save();

        try {
            $prepared = $harvest->prepareHarvest($audience, $this->userId, $this->companyUrl);

            HarvestCompetitorPostJob::dispatch(
                $this->userId,
                $this->audiencePkId,
                0,
                $this->companyUrl,
            );

            Log::info('FetchCompetitorFollowersJob: prepared posts', [
                'audience_id' => $audience->audience_id,
                'company_url' => $this->companyUrl,
                'posts' => count($prepared['post_social_ids']),
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

            Log::error('FetchCompetitorFollowersJob: failed', [
                'audience_id' => $audience->audience_id,
                'company_url' => $this->companyUrl,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}

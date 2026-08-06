<?php

namespace App\Jobs;

use App\Models\AudienceList;
use App\Models\User;
use App\V2\Services\FullEnrichClient;
use App\V2\Services\LeadEnrichmentPersister;
use App\V2\Services\LeadEnrichmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchAudienceEmailBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public bool $deleteWhenMissingModels = true;

    /**
     * @param  array<int>  $audienceListItemIds
     */
    public function __construct(
        public readonly array $audienceListItemIds,
        public readonly int $userId
    ) {
        $this->onQueue('enrichment');
    }

    /**
     * @param  array<int>  $audienceListItemIds
     */
    public static function dispatchChunked(array $audienceListItemIds, int $userId): void
    {
        $ids = array_values(array_unique(array_map('intval', $audienceListItemIds)));
        if ($ids === []) {
            return;
        }

        $chunkSize = max(1, (int) config('services.email_scraping.job_chunk_size', 5));
        $stagger = max(0, (int) config('services.email_scraping.job_chunk_stagger_seconds', 3));

        foreach (array_chunk($ids, $chunkSize) as $i => $chunk) {
            $pending = self::dispatch($chunk, $userId);
            if ($i > 0 && $stagger > 0) {
                $pending->delay(now()->addSeconds($i * $stagger));
            }
        }
    }

    public function handle(
        LeadEnrichmentService $enrichmentService,
        LeadEnrichmentPersister $persister,
    ): void {
        Log::info('[FetchAudienceEmailBatchJob] started', [
            'user_id' => $this->userId,
            'count' => count($this->audienceListItemIds),
        ]);

        $user = User::find($this->userId);
        if (! $user) {
            $this->markItemsRetryable($this->audienceListItemIds, 'User not found for enrichment job.');

            return;
        }

        FullEnrichClient::resetCreditsExhausted();

        $this->checkAndResetDailyLimit($user);
        $user->refresh();

        $dailyLimit = (int) config('services.email_scraping.daily_limit_per_user', 100);
        $items = AudienceList::whereIn('id', $this->audienceListItemIds)->get();
        $lookupsDone = 0;
        $startedAt = microtime(true);
        $softDeadline = $startedAt + max(60, $this->timeout - 150);
        $remainingIds = [];

        foreach ($items as $index => $item) {
            if (microtime(true) >= $softDeadline) {
                $remainingIds = $items->slice($index)->pluck('id')->map(fn ($id) => (int) $id)->all();
                Log::warning('[FetchAudienceEmailBatchJob] soft deadline reached — re-queueing remainder', [
                    'user_id' => $this->userId,
                    'remaining' => count($remainingIds),
                    'elapsed_seconds' => (int) (microtime(true) - $startedAt),
                ]);
                break;
            }

            if (! empty($item->email_fetch_attempted_at) && $item->email_fetch_status === 'completed') {
                continue;
            }

            if ($user->daily_profile_email_scraping_count >= $dailyLimit) {
                Log::warning('[FetchAudienceEmailBatchJob] daily limit reached mid-batch', [
                    'user_id' => $user->id,
                ]);
                AudienceList::query()
                    ->whereIn('id', $items->slice($index)->pluck('id'))
                    ->whereIn('email_fetch_status', ['pending', 'processing'])
                    ->update(['email_fetch_status' => null, 'email_fetch_attempted_at' => null]);
                break;
            }

            $publicIdentifier = trim((string) ($item->con_public_identifier ?? ''));
            if ($publicIdentifier === '' && ! empty($item->con_profile_url) && preg_match('/\/in\/([^\/\?]+)/', $item->con_profile_url, $m)) {
                $publicIdentifier = $m[1];
            }

            if ($publicIdentifier === '') {
                $item->update([
                    'email_fetch_attempted_at' => now(),
                    'email_fetch_status' => 'completed',
                ]);
                continue;
            }

            $item->update(['email_fetch_status' => 'processing']);

            if ($lookupsDone > 0) {
                $this->humanPause();
            }
            $lookupsDone++;

            try {
                $input = $enrichmentService->inputFromAudienceList($item);
                $result = $enrichmentService->enrich($user, $input);
                $persister->persistAudienceLead($item, $result, $user->id);
            } catch (\Throwable $e) {
                $item->update([
                    'email_fetch_status' => null,
                    'email_fetch_attempted_at' => null,
                ]);

                Log::error('[FetchAudienceEmailBatchJob] enrichment failed', [
                    'audience_list_id' => $item->id,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if (! $result->isSoftTimeout()) {
                $user->increment('daily_profile_email_scraping_count');
                $user->refresh();
            }
        }

        if ($remainingIds !== []) {
            AudienceList::query()
                ->whereIn('id', $remainingIds)
                ->whereIn('email_fetch_status', ['pending', 'processing'])
                ->update(['email_fetch_status' => 'pending']);

            self::dispatch($remainingIds, $this->userId)
                ->delay(now()->addSeconds(5));
        }

        Log::info('[FetchAudienceEmailBatchJob] finished', [
            'user_id' => $this->userId,
            'lookups' => $lookupsDone,
            'requeued' => count($remainingIds),
        ]);
    }

    public function failed(?\Throwable $e): void
    {
        Log::error('[FetchAudienceEmailBatchJob] job failed', [
            'user_id' => $this->userId,
            'item_ids' => $this->audienceListItemIds,
            'error' => $e?->getMessage(),
        ]);
        $this->markItemsRetryable($this->audienceListItemIds, $e?->getMessage() ?: 'Enrichment job failed.');
    }

    /**
     * @param  array<int>  $ids
     */
    private function markItemsRetryable(array $ids, string $reason): void
    {
        if ($ids === []) {
            return;
        }

        AudienceList::query()
            ->whereIn('id', $ids)
            ->whereIn('email_fetch_status', ['pending', 'processing'])
            ->update([
                'email_fetch_status' => 'timed_out',
                'email_fetch_attempted_at' => now(),
            ]);

        Log::warning('[FetchAudienceEmailBatchJob] marked items retryable (timed_out)', [
            'ids' => $ids,
            'reason' => $reason,
        ]);
    }

    private function humanPause(): void
    {
        $min = max(0, (int) config('services.unipile_pacing.profile_lookup_delay_min_ms', 1000));
        $max = max($min, (int) config('services.unipile_pacing.profile_lookup_delay_max_ms', 3000));

        if ($max > 0) {
            usleep(random_int($min, $max) * 1000);
        }
    }

    private function checkAndResetDailyLimit(User $user): void
    {
        $today = now()->toDateString();
        $resetDate = $user->daily_profile_email_scraping_reset_at
            ? \Carbon\Carbon::parse($user->daily_profile_email_scraping_reset_at)->toDateString()
            : null;

        if ($resetDate !== $today) {
            $user->update([
                'daily_profile_email_scraping_count' => 0,
                'daily_profile_email_scraping_reset_at' => $today,
            ]);
        }
    }
}

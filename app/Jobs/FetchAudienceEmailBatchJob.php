<?php

namespace App\Jobs;

use App\Models\AudienceList;
use App\Models\User;
use App\V2\Services\LeadEnrichmentPersister;
use App\V2\Services\LeadEnrichmentService;
use App\V2\Services\FullEnrichClient;
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
        $this->onQueue('default');
    }

    public function handle(
        LeadEnrichmentService $enrichmentService,
        LeadEnrichmentPersister $persister,
    ): void {
        $user = User::find($this->userId);
        if (! $user) {
            return;
        }

        FullEnrichClient::resetCreditsExhausted();

        $this->checkAndResetDailyLimit($user);
        $user->refresh();

        $dailyLimit = (int) config('services.email_scraping.daily_limit_per_user', 100);
        $items = AudienceList::whereIn('id', $this->audienceListItemIds)->get();
        $lookupsDone = 0;

        foreach ($items as $item) {
            if (! empty($item->email_fetch_attempted_at) && $item->email_fetch_status === 'completed') {
                continue;
            }

            if ($user->daily_profile_email_scraping_count >= $dailyLimit) {
                Log::warning('FetchAudienceEmailBatchJob: daily limit reached mid-batch', [
                    'user_id' => $user->id,
                ]);
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

                Log::error('FetchAudienceEmailBatchJob: enrichment failed', [
                    'audience_list_id' => $item->id,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            $user->increment('daily_profile_email_scraping_count');
            $user->refresh();
        }
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

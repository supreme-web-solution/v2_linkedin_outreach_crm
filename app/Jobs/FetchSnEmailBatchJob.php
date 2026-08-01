<?php

namespace App\Jobs;

use App\Models\SnLead;
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

class FetchSnEmailBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public bool $deleteWhenMissingModels = true;

    /**
     * @param  array<int>  $snLeadIds
     */
    public function __construct(
        public readonly array $snLeadIds,
        public readonly int $userId,
        public readonly string $listHash,
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
        $leads = SnLead::query()
            ->whereIn('id', $this->snLeadIds)
            ->where('sn_list_id', $this->listHash)
            ->get();
        $lookupsDone = 0;

        foreach ($leads as $lead) {
            if (! empty($lead->email)) {
                $lead->update(['email_fetch_status' => 'completed']);

                continue;
            }

            if ($lead->email_fetch_status === 'completed') {
                continue;
            }

            if ($user->daily_profile_email_scraping_count >= $dailyLimit) {
                Log::warning('FetchSnEmailBatchJob: daily limit reached mid-batch', [
                    'user_id' => $user->id,
                ]);
                break;
            }

            $identifier = trim((string) ($lead->lid ?: $lead->sn_lid ?: ''));
            if ($identifier === '') {
                $lead->update([
                    'email_fetch_attempted_at' => now(),
                    'email_fetch_status' => 'completed',
                ]);

                continue;
            }

            $lead->update(['email_fetch_status' => 'processing']);

            if ($lookupsDone > 0) {
                $this->humanPause();
            }
            $lookupsDone++;

            try {
                $lead->loadMissing('company');
                $result = $enrichmentService->enrich($user, $enrichmentService->inputFromSnLead($lead));
                $persister->persistSnLead($lead, $result, $user->id);
            } catch (\Throwable $e) {
                $lead->update(['email_fetch_status' => 'failed']);

                Log::error('FetchSnEmailBatchJob: enrichment failed', [
                    'sn_lead_id' => $lead->id,
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

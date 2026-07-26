<?php

namespace App\Jobs;

use App\Models\SnLead;
use App\Models\User;
use App\V2\Services\UnipileProfileContactService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchSnPhoneBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

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

    public function handle(UnipileProfileContactService $contactService): void
    {
        $user = User::find($this->userId);
        if (! $user) {
            return;
        }

        $lookupsDone = 0;

        foreach (SnLead::whereIn('id', $this->snLeadIds)->where('sn_list_id', $this->listHash)->get() as $lead) {
            if (! empty($lead->phone) || ! empty($lead->phone_fetch_attempted_at)) {
                continue;
            }

            $identifier = trim((string) ($lead->lid ?: $lead->sn_lid ?: ''));
            if ($identifier === '') {
                $lead->update(['phone_fetch_attempted_at' => now(), 'phone_fetch_status' => 'completed']);
                continue;
            }

            if ($lookupsDone > 0) {
                $this->humanPause();
            }
            $lookupsDone++;

            $lead->update(['phone_fetch_status' => 'processing']);

            try {
                $phone = $contactService->fetchPhoneForUser($user, $identifier);
            } catch (\Throwable $e) {
                $lead->update(['phone_fetch_status' => null, 'phone_fetch_attempted_at' => null]);
                Log::error('FetchSnPhoneBatchJob failed', ['sn_lead_id' => $lead->id, 'error' => $e->getMessage()]);
                continue;
            }

            $lead->update([
                'phone' => $phone,
                'phone_fetch_attempted_at' => now(),
                'phone_fetch_status' => 'completed',
            ]);
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
}

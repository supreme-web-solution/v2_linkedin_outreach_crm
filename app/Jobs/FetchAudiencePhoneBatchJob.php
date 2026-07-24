<?php

namespace App\Jobs;

use App\Models\AudienceList;
use App\Models\SnLead;
use App\Models\User;
use App\V2\Services\UnipileProfileContactService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchAudiencePhoneBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public bool $deleteWhenMissingModels = true;

    /**
     * @param  array<int>  $audienceListItemIds
     */
    public function __construct(
        public readonly array $audienceListItemIds,
        public readonly int $userId,
    ) {
        $this->onQueue('default');
    }

    public function handle(UnipileProfileContactService $contactService): void
    {
        $user = User::find($this->userId);
        if (! $user) {
            return;
        }

        $items = AudienceList::whereIn('id', $this->audienceListItemIds)->get();

        foreach ($items as $item) {
            if (! empty($item->con_phone) || ! empty($item->phone_fetch_attempted_at)) {
                continue;
            }

            $publicIdentifier = trim((string) ($item->con_public_identifier ?? ''));
            if ($publicIdentifier === '' && ! empty($item->con_profile_url) && preg_match('/\/in\/([^\/\?]+)/', $item->con_profile_url, $m)) {
                $publicIdentifier = $m[1];
            }

            if ($publicIdentifier === '') {
                $item->update([
                    'phone_fetch_attempted_at' => now(),
                    'phone_fetch_status' => 'completed',
                ]);
                continue;
            }

            $item->update(['phone_fetch_status' => 'processing']);

            try {
                $phone = $contactService->fetchPhoneForUser($user, $publicIdentifier);
            } catch (\Throwable $e) {
                $item->update([
                    'phone_fetch_status' => null,
                    'phone_fetch_attempted_at' => null,
                ]);

                Log::error('FetchAudiencePhoneBatchJob: profile lookup failed', [
                    'audience_list_id' => $item->id,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if ($phone) {
                $item->update([
                    'con_phone' => $phone,
                    'phone_fetch_status' => 'completed',
                    'phone_fetch_attempted_at' => now(),
                ]);
            } else {
                $item->update([
                    'phone_fetch_attempted_at' => now(),
                    'phone_fetch_status' => 'completed',
                ]);
            }
        }
    }
}

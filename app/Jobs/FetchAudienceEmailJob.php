<?php

namespace App\Jobs;

use App\Models\Audience;
use App\Models\AudienceList;
use App\Models\User;
use App\V2\Services\UnipileProfileEmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchAudienceEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $maxExceptions = 1;

    public int $timeout = 120;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public readonly int $audienceListItemId,
        public readonly string $publicIdentifier
    ) {
        $this->onQueue('default');
    }

    public function tries(): int
    {
        return 1;
    }

    public function failed(\Throwable $exception): void
    {
        $item = AudienceList::find($this->audienceListItemId);
        if (! $item) {
            return;
        }

        $item->update([
            'email_fetch_status' => null,
            'email_fetch_attempted_at' => null,
        ]);

        Log::warning('FetchAudienceEmailJob failed', [
            'audience_list_id' => $this->audienceListItemId,
            'public_identifier' => $this->publicIdentifier,
            'error' => $exception->getMessage(),
        ]);
    }

    public function handle(UnipileProfileEmailService $emailService): void
    {
        $item = AudienceList::find($this->audienceListItemId);
        if (! $item) {
            return;
        }

        $item->update(['email_fetch_status' => 'processing']);

        if (! empty($item->con_email)) {
            $item->update(['email_fetch_status' => 'completed']);

            return;
        }

        $audience = Audience::where('audience_id', $item->audience_id)->first();
        $user = $audience ? User::find($audience->user_id) : null;
        if (! $user) {
            $item->update(['email_fetch_status' => null, 'email_fetch_attempted_at' => null]);

            return;
        }

        $this->checkAndResetDailyLimit($user);
        $user->refresh();

        $dailyLimit = (int) config('services.email_scraping.daily_limit_per_user', 100);
        if ($user->daily_profile_email_scraping_count >= $dailyLimit) {
            throw new \RuntimeException("Daily email lookup limit reached ({$dailyLimit} profiles/day).");
        }

        try {
            $email = $emailService->fetchEmailForUser($user, $this->publicIdentifier);
        } catch (\Throwable $e) {
            $item->update([
                'email_fetch_status' => null,
                'email_fetch_attempted_at' => null,
            ]);

            throw $e;
        }

        $user->increment('daily_profile_email_scraping_count');

        if ($email) {
            $item->update([
                'con_email' => $email,
                'email_fetch_status' => 'completed',
                'email_fetch_attempted_at' => now(),
            ]);

            return;
        }

        $item->update([
            'email_fetch_attempted_at' => now(),
            'email_fetch_status' => 'completed',
        ]);
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

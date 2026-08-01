<?php

namespace App\V2\Services;

use App\Models\User;

class DailyEnrichmentQuotaService
{
    public function payloadForUser(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        $this->checkAndResetDailyLimit($user);
        $user->refresh();

        $dailyLimit = (int) config('services.email_scraping.daily_limit_per_user', 100);
        $used = (int) ($user->daily_profile_email_scraping_count ?? 0);
        $inFlight = app(EmailEnrichmentLimiter::class)->pendingJobCount($user->id);
        $remaining = max(0, $dailyLimit - $used);
        $effectiveRemaining = $dailyLimit <= 0
            ? PHP_INT_MAX
            : max(0, $dailyLimit - $used - $inFlight);

        return [
            'daily_limit' => $dailyLimit,
            'used' => $used,
            'remaining' => $remaining,
            'effective_remaining' => $effectiveRemaining,
            'in_flight' => $inFlight,
            'can_scrape' => $dailyLimit <= 0 || $effectiveRemaining > 0,
            'percent' => $dailyLimit <= 0 ? 0 : min(100, (int) round((($used + $inFlight) / $dailyLimit) * 100)),
            'reset_date' => $user->daily_profile_email_scraping_reset_at,
        ];
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

<?php

namespace App\V2\Services;

use App\Models\Audience;
use App\Models\AudienceList;
use App\Models\SnLead;
use App\Models\SnLeadList;
use App\Models\User;

/**
 * Daily caps and in-flight limits for LinkedIn profile email lookups.
 */
class EmailEnrichmentLimiter
{
    public function pendingJobCount(int $userId): int
    {
        $stuckCutoff = now()->subMinutes(10);

        $userAudienceIds = Audience::query()
            ->where('user_id', $userId)
            ->pluck('audience_id')
            ->all();

        $audiencePending = 0;
        if ($userAudienceIds !== []) {
            AudienceList::query()
                ->whereIn('audience_id', $userAudienceIds)
                ->whereIn('email_fetch_status', ['pending', 'processing'])
                ->where(fn ($q) => $q->where('email_fetch_attempted_at', '<', $stuckCutoff)->orWhereNull('email_fetch_attempted_at'))
                ->update(['email_fetch_status' => null, 'email_fetch_attempted_at' => null]);

            $audiencePending = AudienceList::query()
                ->whereIn('audience_id', $userAudienceIds)
                ->whereIn('email_fetch_status', ['pending', 'processing'])
                ->where('email_fetch_attempted_at', '>=', $stuckCutoff)
                ->count();
        }

        $snListHashes = SnLeadList::query()
            ->where('user_id', $userId)
            ->pluck('list_hash')
            ->all();

        $snPending = 0;
        if ($snListHashes !== []) {
            SnLead::query()
                ->whereIn('sn_list_id', $snListHashes)
                ->whereIn('email_fetch_status', ['pending', 'processing'])
                ->where(fn ($q) => $q->where('email_fetch_attempted_at', '<', $stuckCutoff)->orWhereNull('email_fetch_attempted_at'))
                ->update(['email_fetch_status' => null, 'email_fetch_attempted_at' => null]);

            $snPending = SnLead::query()
                ->whereIn('sn_list_id', $snListHashes)
                ->whereIn('email_fetch_status', ['pending', 'processing'])
                ->where('email_fetch_attempted_at', '>=', $stuckCutoff)
                ->count();
        }

        return $audiencePending + $snPending;
    }

    /**
     * @return array{
     *     email: array<string, mixed>,
     *     pending_email_jobs: int,
     *     lookup_pace_seconds: array{min: float, max: float},
     *     resets_at: string
     * }
     */
    public function limitsPayloadForUser(User $user): array
    {
        $quotaBundle = app(DailyUsageQuotaService::class)->forUser($user);
        $user->refresh();

        $inFlight = $this->pendingJobCount($user->id);
        $email = collect($quotaBundle['items'])->firstWhere('key', 'email_enrichment') ?? [];
        $limit = (int) ($email['limit'] ?? 0);
        $used = (int) ($email['used'] ?? 0);
        $effectiveRemaining = $limit <= 0 ? -1 : max(0, $limit - $used - $inFlight);

        $email = array_merge($email, [
            'in_flight' => $inFlight,
            'effective_remaining' => $effectiveRemaining,
            'at_limit' => $limit > 0 && $effectiveRemaining <= 0,
            'remaining' => $effectiveRemaining >= 0 ? $effectiveRemaining : ($email['remaining'] ?? 0),
            'percent' => $limit <= 0 ? 0.0 : min(100.0, round((($used + $inFlight) / $limit) * 100, 1)),
        ]);

        $minMs = (int) config('services.unipile_pacing.profile_lookup_delay_min_ms', 1000);
        $maxMs = (int) config('services.unipile_pacing.profile_lookup_delay_max_ms', 3000);

        return [
            'email' => $email,
            'pending_email_jobs' => $inFlight,
            'lookup_pace_seconds' => [
                'min' => round($minMs / 1000, 1),
                'max' => round($maxMs / 1000, 1),
            ],
            'resets_at' => $quotaBundle['resets_at'],
        ];
    }

    /**
     * @return array{
     *     allowed: bool,
     *     message: string,
     *     remaining_daily: int,
     *     pending_jobs: int,
     *     max_queue_now: int
     * }
     */
    public function queueCapacity(User $user, int $requested): array
    {
        app(DailyUsageQuotaService::class)->forUser($user);
        $user->refresh();

        $dailyLimit = (int) config('services.email_scraping.daily_limit_per_user', 100);
        $used = (int) ($user->daily_profile_email_scraping_count ?? 0);
        $inFlight = $this->pendingJobCount($user->id);
        $remainingDaily = $dailyLimit <= 0 ? PHP_INT_MAX : max(0, $dailyLimit - $used - $inFlight);
        $pendingJobs = $inFlight;
        $maxConcurrent = 5;

        if ($dailyLimit > 0 && $remainingDaily <= 0) {
            return [
                'allowed' => false,
                'message' => "Daily email enrichment limit reached ({$dailyLimit} profiles/day). Try again tomorrow.",
                'remaining_daily' => 0,
                'pending_jobs' => $pendingJobs,
                'max_queue_now' => 0,
            ];
        }

        if ($pendingJobs >= $maxConcurrent) {
            return [
                'allowed' => false,
                'message' => "You have {$pendingJobs} email lookups running. Wait for them to finish before starting more.",
                'remaining_daily' => $dailyLimit <= 0 ? -1 : $remainingDaily,
                'pending_jobs' => $pendingJobs,
                'max_queue_now' => 0,
            ];
        }

        $capped = $dailyLimit <= 0 ? $requested : min($requested, $remainingDaily);

        return [
            'allowed' => $capped > 0,
            'message' => $capped < $requested
                ? "Only {$capped} of {$requested} profiles can be queued today (daily limit)."
                : "Up to {$capped} profile(s) will be queued.",
            'remaining_daily' => $dailyLimit <= 0 ? -1 : $remainingDaily,
            'pending_jobs' => $pendingJobs,
            'max_queue_now' => $capped,
        ];
    }
}

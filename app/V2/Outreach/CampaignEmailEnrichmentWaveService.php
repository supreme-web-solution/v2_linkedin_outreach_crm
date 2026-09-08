<?php

namespace App\V2\Outreach;

use App\Jobs\FetchAudienceEmailBatchJob;
use App\Jobs\FetchSnEmailBatchJob;
use App\Models\AudienceList;
use App\Models\SnLead;
use App\Models\User;
use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLead;
use App\V2\Services\EmailEnrichmentLimiter;
use Illuminate\Support\Carbon;

/**
 * Auto email enrichment for outreach campaigns that include send_email.
 * Waves of ≤25, always capped by the user's daily enrichment limit; remainder continues next day.
 */
class CampaignEmailEnrichmentWaveService
{
    public function __construct(
        private readonly OutreachLeadReadinessService $readiness,
        private readonly OutreachLeadSyncService $sync,
        private readonly OutreachActivityLogger $logger,
    ) {}

    public function campaignNeedsEmail(V2OutreachCampaign $campaign): bool
    {
        $nodes = is_array($campaign->node_model) ? $campaign->node_model : [];

        return in_array('email', OutreachChannelRegistry::contactRequiredChannelsForNodes($nodes), true);
    }

    /**
     * Refresh emails onto campaign leads from list/overlay, then queue the next ≤25 enrichments.
     *
     * @return array{
     *     needed: bool,
     *     refreshed: int,
     *     queued: int,
     *     remaining_fetchable: int,
     *     daily_remaining: int,
     *     message: string,
     *     deferred_until_tomorrow: bool
     * }
     */
    public function processCampaign(V2OutreachCampaign $campaign, string $trigger = 'auto'): array
    {
        if (! $this->campaignNeedsEmail($campaign)) {
            return $this->result(false, 0, 0, 0, -1, 'Sequence has no email step.', false);
        }

        $user = User::query()->find($campaign->user_id);
        if (! $user) {
            return $this->result(true, 0, 0, 0, 0, 'Campaign owner missing.', false);
        }

        $refreshed = $this->sync->refreshMissingEmails($campaign);

        $leadLists = $this->leadLists($campaign);
        if ($leadLists === []) {
            return $this->result(true, $refreshed, 0, 0, -1, 'No attached lead lists to enrich.', false);
        }

        $nodes = is_array($campaign->node_model) ? $campaign->node_model : [];
        $preview = $this->readiness->previewForLists($leadLists, $nodes, (int) $user->id);
        $fetchable = (int) ($preview['email_fetch']['fetchable'] ?? 0);
        $batches = $preview['email_fetch']['batches'] ?? [];

        if ($fetchable <= 0 || $batches === []) {
            $this->markWaveMeta($campaign, $trigger, 0, $fetchable);

            return $this->result(
                true,
                $refreshed,
                0,
                0,
                $this->dailyRemaining($user),
                $refreshed > 0
                    ? "Synced {$refreshed} email(s). No more profiles left to enrich."
                    : 'No profiles left to enrich for email.',
                false,
            );
        }

        $limiter = app(EmailEnrichmentLimiter::class);
        $waveSize = $limiter->batchSize();
        $capacity = $limiter->queueCapacity($user, min($fetchable, $waveSize));

        if (! ($capacity['allowed'] ?? false)) {
            $dailyLeft = (int) ($capacity['remaining_daily'] ?? 0);
            $deferred = $dailyLeft === 0;
            $message = (string) ($capacity['message'] ?? 'Enrichment not available now.');
            $this->markWaveMeta($campaign, $trigger, 0, $fetchable, $deferred);

            if ($trigger !== 'silent') {
                $this->logger->log(
                    $campaign->id,
                    null,
                    null,
                    null,
                    'info',
                    $deferred
                        ? 'Email enrichment daily limit reached — remaining leads continue tomorrow (waves of '.$waveSize.').'
                        : $message,
                );
            }

            return $this->result(true, $refreshed, 0, $fetchable, $dailyLeft, $message, $deferred);
        }

        $maxNow = (int) ($capacity['max_queue_now'] ?? 0);
        $queued = $this->queueFromBatches($batches, $user->id, $maxNow);
        $remaining = max(0, $fetchable - $queued);
        $dailyLeft = $this->dailyRemaining($user->fresh() ?? $user);

        $this->markWaveMeta($campaign, $trigger, $queued, $remaining);

        $message = $queued > 0
            ? "Queued {$queued} email enrichment lookup(s) (max {$waveSize}/wave). "
                .($remaining > 0
                    ? "About {$remaining} still need enrichment — next wave when capacity frees or tomorrow if daily cap is hit."
                    : 'That should cover remaining fetchable profiles in this wave.')
            : 'No email enrichments queued.';

        if ($queued > 0 || $trigger !== 'silent') {
            $this->logger->log($campaign->id, null, null, null, 'info', $message);
        }

        return $this->result(true, $refreshed, $queued, $remaining, $dailyLeft, $message, false);
    }

    /**
     * Drain email-needed running/preparing campaigns (respects daily + wave caps per user).
     *
     * @return array{campaigns: int, queued: int}
     */
    public function drainDueCampaigns(int $limit = 40): array
    {
        $campaigns = V2OutreachCampaign::query()
            ->whereIn('status', ['preparing', 'running', 'active'])
            ->orderBy('id')
            ->limit(max(1, min(100, $limit)))
            ->get();

        $touched = 0;
        $queued = 0;

        foreach ($campaigns as $campaign) {
            if (! $this->campaignNeedsEmail($campaign)) {
                continue;
            }

            $meta = is_array($campaign->meta) ? $campaign->meta : [];
            $lastAt = (string) ($meta['auto_email_enrich']['last_wave_at'] ?? '');
            if ($lastAt !== '') {
                try {
                    if (Carbon::parse($lastAt)->gt(now()->subMinutes(10))) {
                        // Let the current wave breathe before stacking another.
                        continue;
                    }
                } catch (\Throwable) {
                    // ignore parse errors
                }
            }

            $result = $this->processCampaign($campaign, 'schedule');
            if ($result['needed']) {
                $touched++;
                $queued += (int) $result['queued'];
            }
        }

        return ['campaigns' => $touched, 'queued' => $queued];
    }

    /**
     * Pull a freshly enriched email onto a single outreach lead (before send_email).
     */
    public function refreshLeadEmail(V2OutreachLead $lead): ?string
    {
        if (trim((string) ($lead->email ?? '')) !== '') {
            return trim((string) $lead->email);
        }

        $campaign = $lead->campaign ?? V2OutreachCampaign::query()->find($lead->outreach_campaign_id);
        if (! $campaign) {
            return null;
        }

        $this->sync->refreshMissingEmails($campaign, [(int) $lead->id]);
        $lead->refresh();

        $email = trim((string) ($lead->email ?? ''));

        return $email !== '' ? $email : null;
    }

    /**
     * @param  list<array<string, mixed>>  $batches
     */
    private function queueFromBatches(array $batches, int $userId, int $maxNow): int
    {
        if ($maxNow <= 0) {
            return 0;
        }

        $queued = 0;

        foreach ($batches as $batch) {
            if ($queued >= $maxNow) {
                break;
            }

            $src = (string) ($batch['list_src'] ?? 'aud');
            $slots = $maxNow - $queued;

            if ($src === 'sn') {
                $ids = array_values(array_map('intval', $batch['sn_lead_ids'] ?? $batch['record_ids'] ?? []));
                $ids = array_slice($ids, 0, $slots);
                if ($ids === []) {
                    continue;
                }

                SnLead::query()
                    ->whereIn('id', $ids)
                    ->update([
                        'email_fetch_attempted_at' => now(),
                        'email_fetch_status' => 'pending',
                    ]);

                FetchSnEmailBatchJob::dispatchChunked($ids, $userId, (string) ($batch['list_hash'] ?? ''));
                $queued += count($ids);

                continue;
            }

            $ids = array_values(array_map('intval', $batch['audience_list_ids'] ?? $batch['record_ids'] ?? []));
            $ids = array_slice($ids, 0, $slots);
            if ($ids === []) {
                continue;
            }

            AudienceList::query()
                ->whereIn('id', $ids)
                ->update([
                    'email_fetch_attempted_at' => now(),
                    'email_fetch_status' => 'pending',
                ]);

            FetchAudienceEmailBatchJob::dispatchChunked($ids, $userId);
            $queued += count($ids);
        }

        return $queued;
    }

    /**
     * @return list<array{list_hash: string, list_src: string}>
     */
    private function leadLists(V2OutreachCampaign $campaign): array
    {
        $lists = [];
        foreach ($campaign->outreachLists()->get() as $list) {
            $hash = trim((string) $list->list_hash);
            $src = trim((string) $list->list_src);
            if ($hash === '' || ! in_array($src, ['aud', 'sn', 'csv'], true)) {
                continue;
            }
            $lists[] = ['list_hash' => $hash, 'list_src' => $src];
        }

        if ($lists !== []) {
            return $lists;
        }

        $meta = is_array($campaign->meta) ? $campaign->meta : [];
        $hash = trim((string) ($meta['list_hash'] ?? $meta['ai_plan']['list_hash'] ?? ''));
        $src = trim((string) ($meta['list_src'] ?? $meta['ai_plan']['list_src'] ?? 'aud'));
        if ($hash !== '' && in_array($src, ['aud', 'sn', 'csv'], true)) {
            return [['list_hash' => $hash, 'list_src' => $src]];
        }

        return [];
    }

    private function markWaveMeta(
        V2OutreachCampaign $campaign,
        string $trigger,
        int $queued,
        int $remainingFetchable,
        bool $deferredUntilTomorrow = false,
    ): void {
        $meta = is_array($campaign->meta) ? $campaign->meta : [];
        $meta['auto_email_enrich'] = [
            'enabled' => true,
            'trigger' => $trigger,
            'last_wave_at' => now()->toIso8601String(),
            'last_queued' => $queued,
            'remaining_fetchable' => $remainingFetchable,
            'deferred_until_tomorrow' => $deferredUntilTomorrow,
        ];
        $campaign->forceFill(['meta' => $meta])->save();
    }

    private function dailyRemaining(User $user): int
    {
        $dailyLimit = (int) config('services.email_scraping.daily_limit_per_user', 100);
        if ($dailyLimit <= 0) {
            return -1;
        }

        $used = (int) ($user->daily_profile_email_scraping_count ?? 0);
        $inFlight = app(EmailEnrichmentLimiter::class)->pendingJobCount($user->id);

        return max(0, $dailyLimit - $used - $inFlight);
    }

    /**
     * @return array{
     *     needed: bool,
     *     refreshed: int,
     *     queued: int,
     *     remaining_fetchable: int,
     *     daily_remaining: int,
     *     message: string,
     *     deferred_until_tomorrow: bool
     * }
     */
    private function result(
        bool $needed,
        int $refreshed,
        int $queued,
        int $remainingFetchable,
        int $dailyRemaining,
        string $message,
        bool $deferredUntilTomorrow,
    ): array {
        return [
            'needed' => $needed,
            'refreshed' => $refreshed,
            'queued' => $queued,
            'remaining_fetchable' => $remainingFetchable,
            'daily_remaining' => $dailyRemaining,
            'message' => $message,
            'deferred_until_tomorrow' => $deferredUntilTomorrow,
        ];
    }
}

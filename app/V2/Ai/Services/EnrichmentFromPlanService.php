<?php

namespace App\V2\Ai\Services;

use App\Jobs\FetchAudienceEmailBatchJob;
use App\Jobs\FetchAudiencePhoneBatchJob;
use App\Jobs\FetchSnPhoneBatchJob;
use App\Models\AiActionApproval;
use App\Models\User;
use App\V2\Outreach\OutreachContactEnrichmentService;
use App\V2\Outreach\OutreachLeadReadinessService;
use App\V2\Services\EmailEnrichmentLimiter;

class EnrichmentFromPlanService
{
    /**
     * @return array{queued: int, skipped: int, message: string}
     */
    public function startFromApproval(AiActionApproval $approval, User $user): array
    {
        $existingQueued = (int) data_get($approval->result, 'queued', 0);
        if ($existingQueued > 0) {
            return [
                'queued' => $existingQueued,
                'skipped' => (int) data_get($approval->result, 'skipped', 0),
                'message' => 'Enrichment already queued for this plan.',
            ];
        }

        $payload = $approval->payload ?? [];
        $listHash = trim((string) ($payload['list_hash'] ?? ''));
        $listSrc = trim((string) ($payload['list_src'] ?? ''));
        $mode = (string) ($payload['mode'] ?? 'email');

        if ($listHash === '' || ! in_array($listSrc, ['aud', 'sn', 'csv'], true)) {
            throw new \InvalidArgumentException('Missing lead list in enrichment plan.');
        }

        $leadLists = [['list_hash' => $listHash, 'list_src' => $listSrc]];
        $readiness = app(OutreachLeadReadinessService::class);
        $limiter = app(EmailEnrichmentLimiter::class);

        $queued = 0;
        $skipped = 0;
        $messages = [];

        if (in_array($mode, ['email', 'both'], true)) {
            $preview = $readiness->previewForLists($leadLists, [], $user->id);
            $batches = $preview['email_fetch']['batches'] ?? [];
            $fetchable = (int) ($preview['email_fetch']['fetchable'] ?? 0);

            if ($fetchable <= 0 || $batches === []) {
                $messages[] = 'No profiles eligible for email enrichment on this list.';
            } else {
                $capacity = $limiter->queueCapacity($user, min($fetchable, $limiter->batchSize()));
                if (! $capacity['allowed']) {
                    throw new \RuntimeException((string) ($capacity['message'] ?? 'Enrichment limit reached.'));
                }

                $maxNow = (int) ($capacity['max_queue_now'] ?? 0);
                $left = $maxNow;

                foreach ($batches as $batch) {
                    if ($left <= 0) {
                        break;
                    }
                    $src = (string) ($batch['list_src'] ?? $listSrc);
                    if ($src === 'sn') {
                        $ids = array_values(array_map('intval', $batch['sn_lead_ids'] ?? []));
                        $ids = array_slice($ids, 0, $left);
                        if ($ids === []) {
                            continue;
                        }
                        \App\Models\SnLead::query()->whereIn('id', $ids)->update([
                            'email_fetch_attempted_at' => now(),
                            'email_fetch_status' => 'pending',
                        ]);
                        \App\Jobs\FetchSnEmailBatchJob::dispatchChunked($ids, $user->id, (string) ($batch['list_hash'] ?? $listHash));
                        $queued += count($ids);
                        $left -= count($ids);
                        continue;
                    }

                    $ids = array_values(array_map('intval', $batch['audience_list_ids'] ?? []));
                    $ids = array_slice($ids, 0, $left);
                    if ($ids === []) {
                        continue;
                    }
                    \App\Models\AudienceList::query()->whereIn('id', $ids)->update([
                        'email_fetch_attempted_at' => now(),
                        'email_fetch_status' => 'pending',
                    ]);
                    FetchAudienceEmailBatchJob::dispatchChunked($ids, $user->id);
                    $queued += count($ids);
                    $left -= count($ids);
                }

                $skipped += max(0, $fetchable - $queued);
                if ($queued > 0) {
                    $messages[] = "Queued {$queued} email enrichment job(s) (wave capped; daily limit still applies).";
                }
            }
        }

        if (in_array($mode, ['phone', 'both'], true)) {
            $phoneBatches = app(OutreachContactEnrichmentService::class)->phoneFetchBatches($leadLists, $user->id);
            $batchSize = $limiter->batchSize();

            foreach ($phoneBatches as $batch) {
                $recordIds = array_values(array_filter(array_map('intval', $batch['record_ids'] ?? [])));
                if ($recordIds === []) {
                    continue;
                }

                if (($batch['list_src'] ?? $listSrc) === 'aud') {
                    foreach (array_chunk($recordIds, $batchSize) as $chunk) {
                        FetchAudiencePhoneBatchJob::dispatch($chunk, $user->id);
                        $queued += count($chunk);
                    }
                } else {
                    foreach (array_chunk($recordIds, $batchSize) as $chunk) {
                        FetchSnPhoneBatchJob::dispatch($chunk, $user->id, (string) ($batch['list_hash'] ?? $listHash));
                        $queued += count($chunk);
                    }
                }
            }

            if ($phoneBatches !== []) {
                $messages[] = 'Phone enrichment jobs queued where eligible.';
            }
        }

        if ($queued === 0 && $messages === []) {
            throw new \RuntimeException('Nothing to enrich on this list right now.');
        }

        $approval->update([
            'result' => [
                'queued' => $queued,
                'skipped' => $skipped,
                'mode' => $mode,
                'status' => 'enrichment_queued',
            ],
            'status' => 'executed',
        ]);

        return [
            'queued' => $queued,
            'skipped' => $skipped,
            'message' => implode(' ', $messages),
        ];
    }
}

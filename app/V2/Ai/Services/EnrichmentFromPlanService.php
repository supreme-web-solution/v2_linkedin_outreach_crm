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

            $allIds = [];
            foreach ($batches as $batch) {
                foreach ($batch['audience_list_ids'] as $id) {
                    $allIds[] = (int) $id;
                }
            }

            if ($allIds === []) {
                $messages[] = 'No profiles eligible for email enrichment on this list.';
            } else {
                $capacity = $limiter->queueCapacity($user, count($allIds));
                if (! $capacity['allowed']) {
                    throw new \RuntimeException((string) ($capacity['message'] ?? 'Enrichment limit reached.'));
                }

                $allowedIds = array_slice($allIds, 0, $capacity['max_queue_now']);
                $allowedSet = array_flip($allowedIds);

                foreach ($batches as $batch) {
                    $ids = array_values(array_filter(
                        $batch['audience_list_ids'],
                        fn ($id) => isset($allowedSet[(int) $id]),
                    ));

                    if ($ids === []) {
                        continue;
                    }

                    FetchAudienceEmailBatchJob::dispatchChunked($ids, $user->id);
                    $queued += count($ids);
                }

                $skipped += count($allIds) - $queued;
                if ($queued > 0) {
                    $messages[] = "Queued {$queued} email enrichment job(s).";
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

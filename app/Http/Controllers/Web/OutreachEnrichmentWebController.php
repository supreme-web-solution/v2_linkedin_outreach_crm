<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Jobs\FetchAudienceEmailBatchJob;
use App\Jobs\FetchAudiencePhoneBatchJob;
use App\Jobs\FetchSnPhoneBatchJob;
use App\V2\Outreach\OutreachContactEnrichmentService;
use App\V2\Outreach\OutreachLeadReadinessService;
use App\V2\Services\EmailEnrichmentLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OutreachEnrichmentWebController extends Controller
{
    public function enrichLeads(
        Request $request,
        OutreachLeadReadinessService $readiness,
        EmailEnrichmentLimiter $limiter,
    ): JsonResponse {
        return $this->fetchEmails($request, $readiness, $limiter);
    }

    public function fetchEmails(
        Request $request,
        OutreachLeadReadinessService $readiness,
        EmailEnrichmentLimiter $limiter,
    ): JsonResponse {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        $data = $request->validate([
            'lead_lists' => ['required', 'array', 'min:1'],
            'lead_lists.*.list_hash' => ['required', 'string'],
            'lead_lists.*.list_src' => ['required', 'in:aud,sn,csv'],
            'node_model' => ['nullable', 'array'],
        ]);

        $preview = $readiness->previewForLists(
            $data['lead_lists'],
            $data['node_model'] ?? [],
            $user->id,
        );

        $batches = $preview['email_fetch']['batches'] ?? [];
        if ($batches === []) {
            return response()->json([
                'success' => false,
                'message' => 'No audience profiles are eligible for enrichment.',
            ], 400);
        }

        $allIds = [];
        foreach ($batches as $batch) {
            foreach ($batch['audience_list_ids'] as $id) {
                $allIds[] = (int) $id;
            }
        }

        $requested = count($allIds);
        $capacity = $limiter->queueCapacity($user, $requested);

        if (! $capacity['allowed']) {
            return response()->json([
                'success' => false,
                'message' => $capacity['message'],
                'remaining_daily' => $capacity['remaining_daily'],
                'pending_jobs' => $capacity['pending_jobs'],
                'enrichment_limits' => $limiter->limitsPayloadForUser($user->fresh()),
            ], $capacity['pending_jobs'] >= $limiter->batchSize() ? 429 : 400);
        }

        $allowedIds = array_slice($allIds, 0, $capacity['max_queue_now']);
        $allowedSet = array_flip($allowedIds);
        $queued = 0;

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

        $skipped = $requested - $queued;
        $message = $skipped > 0
            ? "Queued {$queued} enrichment job(s). {$skipped} skipped due to today's daily limit — run again tomorrow."
            : "Queued {$queued} enrichment job(s). LinkedIn profile first, then FullEnrich when configured.";

        return response()->json([
            'success' => true,
            'queued' => $queued,
            'skipped' => $skipped,
            'message' => $message,
            'enrichment_limits' => $limiter->limitsPayloadForUser($user->fresh()),
        ]);
    }

    public function fetchPhones(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        $data = $request->validate([
            'lead_lists' => ['required', 'array', 'min:1'],
            'lead_lists.*.list_hash' => ['required', 'string'],
            'lead_lists.*.list_src' => ['required', 'in:aud,sn,csv'],
        ]);

        $service = app(OutreachContactEnrichmentService::class);
        $batches = $service->phoneFetchBatches($data['lead_lists'], $user->id);
        $queued = 0;

        $batchSize = app(EmailEnrichmentLimiter::class)->batchSize();

        foreach ($batches as $batch) {
            if (($batch['list_src'] ?? 'aud') === 'aud') {
                foreach (array_chunk($batch['record_ids'], $batchSize) as $chunk) {
                    FetchAudiencePhoneBatchJob::dispatch($chunk, $user->id);
                    $queued += count($chunk);
                }
            } else {
                foreach (array_chunk($batch['record_ids'], $batchSize) as $chunk) {
                    FetchSnPhoneBatchJob::dispatch($chunk, $user->id, $batch['list_hash']);
                    $queued += count($chunk);
                }
            }
        }

        return response()->json([
            'success' => true,
            'queued' => $queued,
            'message' => $queued > 0
                ? "Queued phone lookup for {$queued} profile(s). Each profile is checked one at a time with a short delay."
                : 'No profiles eligible for phone fetch.',
        ]);
    }

    public function verifyWhatsApp(Request $request, OutreachContactEnrichmentService $service): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        $data = $request->validate([
            'lead_lists' => ['required', 'array', 'min:1'],
            'lead_lists.*.list_hash' => ['required', 'string'],
            'lead_lists.*.list_src' => ['required', 'in:aud,sn,csv'],
        ]);

        $candidates = $service->whatsAppVerifyCandidates($data['lead_lists'], $user->id);
        $result = $service->verifyWhatsAppBatch(
            $user,
            $candidates,
            app(EmailEnrichmentLimiter::class)->batchSize(),
        );

        return response()->json([
            'success' => true,
            'verified' => $result['verified'],
            'failed' => $result['failed'],
            'remaining' => $result['remaining'],
            'message' => sprintf(
                'Verified %d WhatsApp number(s). %d could not be verified.%s',
                $result['verified'],
                $result['failed'],
                $result['remaining'] > 0 ? " Run again for {$result['remaining']} more." : '',
            ),
        ]);
    }

    public function resolveHandles(Request $request, OutreachContactEnrichmentService $service): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        $data = $request->validate([
            'lead_lists' => ['required', 'array', 'min:1'],
            'lead_lists.*.list_hash' => ['required', 'string'],
            'lead_lists.*.list_src' => ['required', 'in:aud,sn,csv'],
        ]);

        $candidates = $service->handleResolveCandidates($data['lead_lists'], $user->id);
        $result = $service->resolveHandlesBatch(
            $user,
            $candidates,
            app(EmailEnrichmentLimiter::class)->batchSize(),
        );

        $skippedNote = ($result['skipped'] ?? 0) > 0
            ? ' '.($result['skipped']).' skipped (connect that channel under Integrations first).'
            : '';

        return response()->json([
            'success' => true,
            'resolved' => $result['resolved'],
            'failed' => $result['failed'],
            'skipped' => $result['skipped'] ?? 0,
            'remaining' => $result['remaining'],
            'message' => sprintf(
                'Resolved %d handle(s). %d could not be resolved.%s%s',
                $result['resolved'],
                $result['failed'],
                $skippedNote,
                $result['remaining'] > 0 ? " Run again for {$result['remaining']} more." : '',
            ),
        ]);
    }

    public function prepareContacts(Request $request, OutreachContactEnrichmentService $service): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        $data = $request->validate([
            'lead_lists' => ['required', 'array', 'min:1'],
            'lead_lists.*.list_hash' => ['required', 'string'],
            'lead_lists.*.list_src' => ['required', 'in:aud,sn,csv'],
            'node_model' => ['nullable', 'array'],
        ]);

        $result = $service->prepareContactsBatch(
            $user,
            $data['lead_lists'],
            $data['node_model'] ?? [],
        );

        $limiter = app(EmailEnrichmentLimiter::class);

        return response()->json([
            'success' => true,
            ...$result,
            'enrichment_limits' => $limiter->limitsPayloadForUser($user->fresh()),
        ]);
    }
}

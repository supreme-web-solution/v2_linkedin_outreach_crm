<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Jobs\FetchAudiencePhoneBatchJob;
use App\Jobs\FetchSnPhoneBatchJob;
use App\V2\Outreach\OutreachContactEnrichmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OutreachEnrichmentWebController extends Controller
{
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

        foreach ($batches as $batch) {
            if (($batch['list_src'] ?? 'aud') === 'aud') {
                foreach (array_chunk($batch['record_ids'], 50) as $chunk) {
                    FetchAudiencePhoneBatchJob::dispatch($chunk, $user->id);
                    $queued += count($chunk);
                }
            } else {
                foreach (array_chunk($batch['record_ids'], 25) as $chunk) {
                    FetchSnPhoneBatchJob::dispatch($chunk, $user->id, $batch['list_hash']);
                    $queued += count($chunk);
                }
            }
        }

        return response()->json([
            'success' => true,
            'queued' => $queued,
            'message' => $queued > 0
                ? "Queued phone fetch for {$queued} profile(s)."
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
        $result = $service->verifyWhatsAppBatch($user, $candidates, 25);

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
        $result = $service->resolveHandlesBatch($user, $candidates, 25);

        return response()->json([
            'success' => true,
            'resolved' => $result['resolved'],
            'failed' => $result['failed'],
            'remaining' => $result['remaining'],
            'message' => sprintf(
                'Resolved %d handle(s). %d could not be resolved.%s',
                $result['resolved'],
                $result['failed'],
                $result['remaining'] > 0 ? " Run again for {$result['remaining']} more." : '',
            ),
        ]);
    }
}

<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Jobs\V2\ProcessUnipileWebhookEventJob;
use App\V2\Integrations\ProviderManager;
use App\V2\Services\OutreachPersistenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class ProviderWebhookController extends Controller
{
    public function __construct(
        private readonly ProviderManager $providerManager,
        private readonly OutreachPersistenceService $persistenceService
    )
    {
    }

    public function unipile(Request $request): JsonResponse
    {
        \Illuminate\Support\Facades\Log::info('[Unipile webhook] callback received', [
            'path' => $request->path(),
            'query' => $request->query(),
            'event_type' => $request->input('type') ?? $request->input('event'),
            'account_id' => $request->input('account_id'),
        ]);

        $provider = $this->providerManager->webhook('unipile');
        $rawBody = (string) $request->getContent();

        if (!$provider->verifySignature($request->headers->all(), $rawBody)) {
            return response()->json(['message' => 'Invalid webhook signature.'], 401);
        }

        $payload = $request->all();
        $parsedPayload = $provider->parseEvent($payload);
        $eventId = $provider->eventId($payload);
        $eventType = $provider->eventType($payload);
        $organizationId = (int) ($payload['organization_id'] ?? $request->query('organization_id', 0));
        $userId = $organizationId > 0
            ? $this->persistenceService->resolveUserIdFromOrganization($organizationId)
            : null;

        if ($userId === null) {
            $accountId = (string) (
                Arr::get($payload, 'account_id')
                ?? Arr::get($parsedPayload, 'data.account_id')
                ?? Arr::get($payload, 'account.id')
                ?? ''
            );

            if ($accountId !== '') {
                $userId = $this->persistenceService->resolveUserIdFromUnipileAccount($accountId);
            }
        }

        if ($userId === null) {
            $name = (string) (Arr::get($payload, 'name') ?? Arr::get($parsedPayload, 'name') ?? '');
            if ($name !== '' && ctype_digit($name)) {
                $userId = (int) $name;
            }
        }

        $record = $this->persistenceService->createProviderAuditEvent(
            $userId,
            $eventType,
            $eventId,
            $parsedPayload
        );

        if (!$record->processed_at) {
            // Account-connect/disconnect events must be reflected quickly in UI.
            if (str_starts_with($eventType, 'account.')) {
                ProcessUnipileWebhookEventJob::dispatchSync($record->id);
            } else {
                ProcessUnipileWebhookEventJob::dispatch($record->id);
            }
        }

        return response()->json([
            'message' => 'ok',
            'event_id' => $record->event_id,
            'queued' => true,
        ]);
    }
}

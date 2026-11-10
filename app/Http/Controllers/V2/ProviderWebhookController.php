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
        $provider = $this->providerManager->webhook('unipile');
        $rawBody = (string) $request->getContent();

        if (!$provider->verifySignature($request->headers->all(), $rawBody)) {
            \Illuminate\Support\Facades\Log::warning('[Unipile webhook] invalid signature', [
                'path' => $request->path(),
            ]);

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

        $accountId = (string) (
            Arr::get($payload, 'account_id')
            ?? Arr::get($parsedPayload, 'data.account_id')
            ?? Arr::get($payload, 'account.id')
            ?? ''
        );

        if ($userId === null && $accountId !== '') {
            $userId = $this->persistenceService->resolveUserIdFromUnipileAccount($accountId);
        }

        if ($userId === null) {
            $name = (string) (Arr::get($payload, 'name') ?? Arr::get($parsedPayload, 'name') ?? '');
            if ($name !== '' && ctype_digit($name)) {
                $userId = (int) $name;
            }
        }

        \Illuminate\Support\Facades\Log::info('[Unipile webhook] received', [
            'event_type' => $eventType,
            'event_id' => $eventId,
            'account_id' => $accountId !== '' ? $accountId : null,
            'user_id' => $userId,
            'raw_type' => Arr::get($payload, 'type') ?? Arr::get($payload, 'event'),
        ]);

        $record = $this->persistenceService->createProviderAuditEvent(
            $userId,
            $eventType,
            $eventId,
            $parsedPayload
        );

        if (!$record->processed_at) {
            // Process inbound + account events inline so inbox updates even if the
            // webhooks queue worker is lagged/stuck. Keep other events async.
            $processInline = str_starts_with($eventType, 'account.')
                || in_array($eventType, [
                    'mail.received',
                    'message.received',
                    'message.sent',
                    'message.reaction',
                    'email.replied',
                    'email.bounced',
                    'email.opened',
                    'email.clicked',
                    'email.sent',
                    'new_relation',
                ], true);

            if ($userId === null) {
                \Illuminate\Support\Facades\Log::warning('[Unipile webhook] no user mapped for account', [
                    'event_type' => $eventType,
                    'event_id' => $eventId,
                    'account_id' => $accountId !== '' ? $accountId : null,
                ]);
            }

            if ($processInline) {
                ProcessUnipileWebhookEventJob::dispatchSync($record->id);
            } else {
                ProcessUnipileWebhookEventJob::dispatch($record->id);
            }
        }

        return response()->json([
            'message' => 'ok',
            'event_id' => $record->event_id,
            'queued' => ! in_array($eventType, [
                'mail.received',
                'message.received',
                'message.sent',
                'message.reaction',
            ], true) && ! str_starts_with($eventType, 'account.'),
            'user_id' => $userId,
        ]);
    }
}

<?php

namespace App\V2\Ai\Services;

use App\Models\AiChannelIdentity;
use App\Models\User;
use App\Jobs\V2\ProcessZernioInboundMessageJob;
use App\V2\Ai\Integrations\ZernioClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ZernioWebhookService
{
    public function __construct(
        private readonly ZernioClient $zernio,
        private readonly ChannelIdentityService $identities,
        private readonly AgentOrchestrator $orchestrator,
        private readonly ZernioWebhookIdempotencyService $idempotency,
        private readonly ZernioTypingIndicatorService $typing,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status:int, body:array<string,mixed>}
     */
    public function handle(array $payload): array
    {
        $parsed = $this->zernio->parseWebhookEvent($payload);
        $event = $parsed['event'];
        $eventId = $parsed['event_id'];

        Log::info('[Zernio] webhook received', [
            'event_id' => $eventId,
            'event' => $event,
            'from' => $parsed['from'] !== '' ? $parsed['from'] : null,
            'text_preview' => Str::limit($parsed['text'], 120),
            'conversation_id' => $parsed['conversation_id'],
            'account_id' => $parsed['account_id'],
            'is_inbound_message' => $parsed['is_inbound_message'],
        ]);

        if ($event === 'webhook.test') {
            return $this->ok(['test' => true]);
        }

        if ($eventId !== '' && ! $this->idempotency->claim($eventId, $event, ['event' => $event])) {
            return $this->ok(['duplicate' => true]);
        }

        try {
            if (! $parsed['is_inbound_message'] && $event !== 'legacy.inbound') {
                return $this->ok(['ignored' => true, 'event' => $event]);
            }

            return $this->handleInboundMessage($parsed);
        } catch (\Throwable $e) {
            report($e);

            return ['status' => 500, 'body' => ['ok' => false]];
        } finally {
            if ($eventId !== '') {
                $this->idempotency->markProcessed($eventId);
            }
        }
    }

    /**
     * @param  array{
     *     event_id: string,
     *     event: string,
     *     message_id: ?string,
     *     text: string,
     *     from: string,
     *     conversation_id: ?string,
     *     account_id: ?string,
     *     is_inbound_message: bool,
     *     media: ?array{type:string,url:string,mime:?string}
     * }  $parsed
     * @return array{status:int, body:array<string,mixed>}
     */
    private function handleInboundMessage(array $parsed): array
    {
        $from = $parsed['from'];
        $text = $parsed['text'];
        $media = $parsed['media'] ?? null;
        $mediaType = is_array($media) ? Str::lower((string) ($media['type'] ?? '')) : '';

        if ($from === '') {
            Log::warning('[Zernio] inbound message ignored', [
                'reason' => 'empty_from',
                'message_id' => $parsed['message_id'],
            ]);

            return $this->ok(['ignored' => true, 'reason' => 'empty_from']);
        }

        if ($text === '' && $media === null) {
            Log::warning('[Zernio] inbound message ignored', [
                'reason' => 'empty_from_or_text',
                'from' => $from,
                'message_id' => $parsed['message_id'],
            ]);

            return $this->ok(['ignored' => true, 'reason' => 'empty_from_or_text']);
        }

        // Voice notes: transcribe → same Alex text path (caption optional).
        if ($mediaType === 'audio' && $text === '') {
            $transcript = app(WhatsAppVoiceTranscriptionService::class)->transcribe($media);
            if ($transcript === null || $transcript === '') {
                $this->zernio->sendText(
                    $this->identities->normalizeExternalId('whatsapp', $from),
                    "I couldn't hear that voice note clearly. Please try again, or type your message.",
                );

                return $this->ok(['ignored' => true, 'reason' => 'voice_transcription_failed']);
            }

            $text = $transcript;
            Log::info('[Zernio] voice note transcribed', [
                'from' => $from,
                'message_id' => $parsed['message_id'],
                'transcript_preview' => Str::limit($transcript, 120),
            ]);
        }

        // Images still need a caption (used as the command for Alex).
        if ($text === '' && $mediaType === 'image') {
            Log::info('[Zernio] inbound image ignored (caption required)', [
                'from' => $from,
                'message_id' => $parsed['message_id'],
            ]);

            return $this->ok(['ignored' => true, 'reason' => 'image_only']);
        }

        if ($text === '' && $media !== null) {
            Log::info('[Zernio] inbound media ignored (unsupported without text)', [
                'from' => $from,
                'message_id' => $parsed['message_id'],
                'media_type' => $mediaType !== '' ? $mediaType : null,
            ]);

            return $this->ok(['ignored' => true, 'reason' => 'unsupported_media']);
        }

        $link = $this->identities->findValidCode($text);
        if ($link) {
            $identity = $this->identities->linkFromCode($link, $from);
            $this->identities->syncZernioInboxContext(
                $identity,
                $parsed['conversation_id'],
                $parsed['account_id'],
            );

            $user = User::query()->find($identity->user_id);
            $name = $user?->name ?? 'there';
            $this->zernio->sendToIdentity(
                $identity->fresh(),
                "You're connected to SociFusion as {$name}.\n"
                ."This WhatsApp is your Command Center — same brain as the web app.\n"
                .'Try: describe a sales goal, or send help',
            );

            Log::info('[Zernio] whatsapp linked', [
                'identity_id' => $identity->id,
                'user_id' => $identity->user_id,
                'organization_id' => $identity->organization_id,
                'external_id' => $identity->external_id,
            ]);

            return $this->ok(['linked' => true]);
        }

        $identity = $this->identities->resolve('whatsapp', $from);
        if (! $identity) {
            $this->zernio->sendText(
                $this->identities->normalizeExternalId('whatsapp', $from),
                'Open SociFusion → AI Employee → Connect WhatsApp, then send your LINK code here.',
            );

            return $this->ok(['unlinked' => true]);
        }

        $this->identities->syncZernioInboxContext(
            $identity,
            $parsed['conversation_id'],
            $parsed['account_id'],
        );
        $identity = $identity->fresh();

        $user = User::query()->findOrFail($identity->user_id);
        $conversation = app(CommandCenterService::class)->conversation(
            $user,
            (int) $identity->organization_id,
            $identity->id,
        );

        if ($mediaType === 'image') {
            app(WhatsAppMediaIngestService::class)->storeForConversation($conversation, $user, $media);
        }

        // Soft-tag voice so Command Center history can show it came from audio.
        if ($mediaType === 'audio') {
            $text = "[Voice note]\n".$text;
        }

        if ((bool) config('socifusion_ai.zernio.queue_inbound', true)) {
            try {
                ProcessZernioInboundMessageJob::dispatch(
                    identityId: $identity->id,
                    userId: $user->id,
                    organizationId: (int) $identity->organization_id,
                    message: $text,
                    providerMessageId: $parsed['message_id'],
                );

                Log::info('[Zernio] inbound message queued', [
                    'identity_id' => $identity->id,
                    'user_id' => $user->id,
                    'message_id' => $parsed['message_id'],
                    'queue' => config('queue.default'),
                    'from_voice' => $mediaType === 'audio',
                ]);

                return $this->ok(['queued' => true, 'from_voice' => $mediaType === 'audio']);
            } catch (\Throwable $e) {
                Log::warning('[Zernio] queue unavailable — processing synchronously', [
                    'identity_id' => $identity->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->typing->begin($identity);

        try {
            $result = $this->orchestrator->handle(
                user: $user,
                organizationId: (int) $identity->organization_id,
                message: $text,
                channel: 'whatsapp',
                channelIdentityId: $identity->id,
                providerMessageId: $parsed['message_id'],
            );

            $this->deliverOrchestratorReply($identity, $user, $result);
        } finally {
            $this->typing->end($identity->id);
        }

        return $this->ok([
            'conversation_id' => $result['conversation_id'] ?? null,
            'duplicate' => $result['duplicate'] ?? false,
            'from_voice' => $mediaType === 'audio',
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function deliverOrchestratorReply(AiChannelIdentity $identity, User $user, array $result): void
    {
        if ($result['duplicate'] ?? false) {
            return;
        }

        $reply = trim((string) ($result['reply'] ?? ''));
        if ($reply === '') {
            return;
        }

        $buttons = null;
        $pendingIds = collect($result['pending_approvals'] ?? [])
            ->pluck('id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();
        $latest = $result['latest_approval'] ?? null;
        $latestId = is_array($latest) ? (int) ($latest['id'] ?? 0) : 0;

        if ($latestId > 0 && in_array($latestId, $pendingIds, true)) {
            $tool = is_array($latest) ? ($latest['tool'] ?? null) : null;
            $payload = is_array($latest) ? ($latest['payload'] ?? null) : null;
            if (! is_array($payload)) {
                $fromList = collect($result['pending_approvals'] ?? [])
                    ->first(fn ($row) => is_array($row) && (int) ($row['id'] ?? 0) === $latestId);
                $tool = is_array($fromList) ? ($fromList['tool'] ?? $tool) : $tool;
                $payload = is_array($fromList) ? ($fromList['payload'] ?? null) : null;
            }

            $buttons = $this->zernio->approvalButtons(
                $latestId,
                is_string($tool) ? $tool : null,
                is_array($payload) ? $payload : null,
            );
        }

        $sent = $this->zernio->sendToIdentity($identity, $reply, $buttons);
        if (! $sent) {
            Log::warning('[Zernio] failed to deliver orchestrator reply', [
                'identity_id' => $identity->id,
                'user_id' => $user->id,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function ok(array $body): array
    {
        return ['status' => 200, 'body' => array_merge(['ok' => true], $body)];
    }
}

<?php

namespace App\Jobs\V2;

use App\Models\V2Call;
use App\Models\V2Conversation;
use App\Models\V2Message;
use App\V2\Campaign\CampaignLinkedInGuard;
use App\V2\Integrations\ProviderManager;
use App\V2\Integrations\Unipile\UnipileException;
use App\V2\Services\CallOrchestrationService;
use App\V2\Services\OutreachPersistenceService;
use App\V2\Services\OutreachUserErrorMapper;
use App\V2\Services\UnipileDailyActionLimiter;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Throwable;

class ProcessOutboundOutreachJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 30, 60, 120];

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $action,
        public readonly int $userId,
        public readonly int $organizationId,
        public readonly int $conversationId,
        public readonly int $messageId,
        public readonly array $payload
    ) {
        $this->onQueue('outreach');
    }

    public function handle(ProviderManager $providerManager, OutreachPersistenceService $persistence, CampaignLinkedInGuard $linkedInGuard): void
    {
        $message = V2Message::query()->find($this->messageId);
        if (!$message) {
            return;
        }

        if (!$this->reserveDailyQuota($message)) {
            return;
        }

        try {
            $this->executeOutreach($providerManager, $persistence, $message);
        } catch (Throwable $exception) {
            if ($this->action === 'message'
                && OutreachUserErrorMapper::isStaleProviderChatError($exception)
                && $this->attemptStaleChatRecovery($providerManager, $persistence, $message, $exception)) {
                return;
            }

            if ($linkedInGuard->isDisconnected($exception)) {
                $linkedInGuard->handleDisconnect(
                    $this->userId,
                    $this->organizationId,
                    $exception->getMessage(),
                );
                $this->markMessageDisconnected($message, $exception->getMessage());

                return;
            }

            if (OutreachUserErrorMapper::isNonRetryable($exception)) {
                $this->fail($exception);

                return;
            }

            throw $exception;
        }
    }

    private function executeOutreach(ProviderManager $providerManager, OutreachPersistenceService $persistence, V2Message $message): void
    {
        $providerKey = $providerManager->defaultProvider();

        $context = [
            'owner_id' => (string) $this->userId,
            'organization_id' => $this->organizationId,
        ];

        $payload = $this->payload;
        if (! empty($payload['_unipile_account_id']) && empty($payload['account_id'])) {
            $payload['account_id'] = $payload['_unipile_account_id'];
        }

        if (! empty($payload['account_id'])) {
            $context['account_id'] = $payload['account_id'];
        }

        $result = match ($this->action) {
            'invite' => $providerManager->invitation($providerKey)->sendInvitation($payload, $context),
            'start_chat' => $providerManager->messaging($providerKey)->startChat($payload, $context),
            'message' => $this->sendMessageOrThrow($providerManager, $providerKey, $payload, $context),
            default => ['error' => 'Unsupported action'],
        };

        $persistence->markMessageResult($message, $result, 'sent');

        if ($this->action === 'start_chat') {
            $this->afterStartChat($message, $result);
        }

        $persistence->createProviderAuditEvent(
            $this->userId,
            'outbound.'.$this->action.'.sent',
            'outbound_'.$this->action.'_'.$this->messageId.'_'.time(),
            [
                'conversation_id' => $this->conversationId,
                'message_id' => $this->messageId,
                'result' => $result,
            ]
        );
    }

    /**
     * Reserve per-user daily quota for this action. Consumed at most once per
     * message (retries reuse the original reservation). When the cap is hit,
     * the send is re-queued for the next day instead of failing.
     */
    private function reserveDailyQuota(V2Message $message): bool
    {
        $quotaAction = match ($this->action) {
            'invite' => UnipileDailyActionLimiter::ACTION_INVITES,
            'start_chat' => UnipileDailyActionLimiter::ACTION_NEW_CHATS,
            'message' => UnipileDailyActionLimiter::ACTION_MESSAGES,
            default => null,
        };

        if ($quotaAction === null) {
            return true;
        }

        $meta = is_array($message->meta) ? $message->meta : [];
        if (!empty($meta['quota_consumed_at'])) {
            return true;
        }

        $limiter = app(UnipileDailyActionLimiter::class);

        if ($limiter->tryConsume($this->userId, $quotaAction)) {
            $meta['quota_consumed_at'] = now()->toIso8601String();
            unset($meta['deferred_until']);
            $message->forceFill(['meta' => $meta])->save();

            return true;
        }

        $resumeAt = $limiter->resumeAt();
        $meta['status'] = 'deferred';
        $meta['deferred_until'] = $resumeAt->toIso8601String();
        $meta['deferred_reason'] = 'daily_'.$quotaAction.'_limit';
        $message->forceFill(['meta' => $meta])->save();

        Log::info('[Outreach] Daily quota reached — send deferred', [
            'user_id' => $this->userId,
            'action' => $this->action,
            'quota' => $quotaAction,
            'message_id' => $this->messageId,
            'resume_at' => $resumeAt->toIso8601String(),
        ]);

        self::dispatch(
            $this->action,
            $this->userId,
            $this->organizationId,
            $this->conversationId,
            $this->messageId,
            $this->payload
        )->delay($resumeAt);

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function sendMessageOrThrow(
        ProviderManager $providerManager,
        string $providerKey,
        array $payload,
        array $context
    ): array {
        return $providerManager->messaging($providerKey)->sendMessage(
            (string) ($this->payload['chat_id'] ?? ''),
            array_filter([
                'text' => (string) ($this->payload['text'] ?? ''),
                'account_id' => $payload['account_id'] ?? null,
            ]),
            $context
        );
    }

    private function attemptStaleChatRecovery(
        ProviderManager $providerManager,
        OutreachPersistenceService $persistence,
        V2Message $message,
        Throwable $originalException
    ): bool {
        $meta = is_array($message->meta) ? $message->meta : [];
        if (!empty($meta['chat_recovery_attempted'])) {
            return false;
        }

        $conversation = V2Conversation::query()->find($this->conversationId);
        if (!$conversation) {
            return false;
        }

        $staleChatId = trim((string) (
            $this->payload['chat_id']
            ?? $conversation->provider_chat_id
            ?? ''
        ));

        if ($staleChatId !== '') {
            $persistence->invalidateProviderChatId(
                $conversation,
                $staleChatId,
                'stale_provider_chat_on_send'
            );
            $conversation = $conversation->fresh() ?? $conversation;
        }

        $attendeeIds = $persistence->resolveAttendeeIdsForConversation(
            $conversation,
            $this->userId,
            $this->organizationId
        );

        if ($attendeeIds === []) {
            Log::warning('[Outreach] Stale chat recovery skipped — no attendee ids', [
                'conversation_id' => $this->conversationId,
                'message_id' => $this->messageId,
                'stale_chat_id' => $staleChatId !== '' ? $staleChatId : null,
            ]);

            return false;
        }

        $text = trim((string) ($this->payload['text'] ?? $message->body ?? ''));
        if ($text === '') {
            return false;
        }

        $this->releaseReservedQuota($message, UnipileDailyActionLimiter::ACTION_MESSAGES);

        if (!$this->reserveRecoveryStartChatQuota($message, $attendeeIds, $text)) {
            return true;
        }

        $payload = $this->payload;
        if (!empty($payload['_unipile_account_id']) && empty($payload['account_id'])) {
            $payload['account_id'] = $payload['_unipile_account_id'];
        }

        $context = array_filter([
            'owner_id' => (string) $this->userId,
            'organization_id' => $this->organizationId,
            'account_id' => $payload['account_id'] ?? null,
        ]);

        $startPayload = [
            'attendee_ids' => $attendeeIds,
            'text' => $text,
        ] + array_filter([
            'account_id' => $payload['account_id'] ?? null,
        ]);

        try {
            $result = $providerManager
                ->messaging($providerManager->defaultProvider())
                ->startChat($startPayload, $context);
        } catch (Throwable $recoveryException) {
            if ($recoveryException instanceof UnipileException
                && OutreachUserErrorMapper::isStaleProviderChatError($recoveryException)) {
                return false;
            }

            throw $recoveryException;
        }

        $meta['chat_recovery_attempted'] = true;
        $meta['recovered_from_chat_id'] = $staleChatId !== '' ? $staleChatId : null;
        $meta['action'] = 'start_chat';
        $meta['attendee_ids'] = $attendeeIds;
        $message->forceFill(['meta' => $meta])->save();

        if ($attendeeIds !== []) {
            $conversationMeta = is_array($conversation->meta) ? $conversation->meta : [];
            $conversationMeta['attendee_ids'] = $attendeeIds;
            $conversation->forceFill(['meta' => $conversationMeta])->save();
        }

        Log::info('[Outreach] Recovered stale provider chat via start_chat', [
            'conversation_id' => $this->conversationId,
            'message_id' => $this->messageId,
            'stale_chat_id' => $staleChatId !== '' ? $staleChatId : null,
            'attendee_ids' => $attendeeIds,
        ]);

        $persistence->markMessageResult($message, $result, 'sent');
        $this->afterStartChat($message, $result);

        $persistence->createProviderAuditEvent(
            $this->userId,
            'outbound.start_chat.sent',
            'outbound_start_chat_recovery_'.$this->messageId.'_'.time(),
            [
                'conversation_id' => $this->conversationId,
                'message_id' => $this->messageId,
                'recovered_from_chat_id' => $staleChatId !== '' ? $staleChatId : null,
                'original_error' => $originalException->getMessage(),
                'result' => $result,
            ]
        );

        return true;
    }

    private function releaseReservedQuota(V2Message $message, string $action): void
    {
        $meta = is_array($message->meta) ? $message->meta : [];
        if (empty($meta['quota_consumed_at'])) {
            return;
        }

        app(UnipileDailyActionLimiter::class)->release($this->userId, $action);
        unset($meta['quota_consumed_at']);
        $message->forceFill(['meta' => $meta])->save();
    }

    /**
     * @param  array<int, string>  $attendeeIds
     */
    private function reserveRecoveryStartChatQuota(V2Message $message, array $attendeeIds, string $text): bool
    {
        $limiter = app(UnipileDailyActionLimiter::class);

        if (!$limiter->tryConsume($this->userId, UnipileDailyActionLimiter::ACTION_NEW_CHATS)) {
            $resumeAt = $limiter->resumeAt();
            $meta = is_array($message->meta) ? $message->meta : [];
            $meta['status'] = 'deferred';
            $meta['deferred_until'] = $resumeAt->toIso8601String();
            $meta['deferred_reason'] = 'daily_'.UnipileDailyActionLimiter::ACTION_NEW_CHATS.'_limit';
            $meta['chat_recovery_attempted'] = true;
            $message->forceFill(['meta' => $meta])->save();

            $payload = $this->payload;
            unset($payload['chat_id']);

            self::dispatch(
                'start_chat',
                $this->userId,
                $this->organizationId,
                $this->conversationId,
                $this->messageId,
                $payload + [
                    'attendee_ids' => $attendeeIds,
                    'text' => $text,
                ]
            )->delay($resumeAt);

            Log::info('[Outreach] Stale chat recovery deferred — new chat quota reached', [
                'user_id' => $this->userId,
                'message_id' => $this->messageId,
                'resume_at' => $resumeAt->toIso8601String(),
            ]);

            return false;
        }

        $meta = is_array($message->meta) ? $message->meta : [];
        $meta['quota_consumed_at'] = now()->toIso8601String();
        unset($meta['deferred_until']);
        $message->forceFill(['meta' => $meta])->save();

        return true;
    }

    private function markMessageDisconnected(V2Message $message, string $reason): void
    {
        $meta = is_array($message->meta) ? $message->meta : [];
        $meta['status'] = 'failed';
        $meta['error'] = $reason;
        $meta['failure_reason'] = 'linkedin_disconnected';

        $message->forceFill(['meta' => $meta])->save();
    }

    public function failed(Throwable $exception): void
    {
        $message = V2Message::query()->find($this->messageId);
        if (!$message) {
            return;
        }

        $meta = is_array($message->meta) ? $message->meta : [];
        $meta['status'] = 'failed';
        $meta['error'] = $exception->getMessage();

        $message->forceFill(['meta' => $meta])->save();

        if ($this->action === 'start_chat') {
            app(CallOrchestrationService::class)->rollbackFailedLaunch(
                (int) Arr::get($message->meta ?? [], 'call_id', 0),
                $exception,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function afterStartChat(V2Message $message, array $result): void
    {
        $chatId = (string) (Arr::get($result, 'id')
            ?? Arr::get($result, 'chat_id')
            ?? Arr::get($result, 'data.id')
            ?? '');

        if ($chatId === '') {
            return;
        }

        $conversation = V2Conversation::query()->find($this->conversationId);
        if ($conversation && !$conversation->provider_chat_id) {
            $conversation->forceFill(['provider_chat_id' => $chatId])->save();
        }

        $meta = is_array($message->meta) ? $message->meta : [];
        $callId = (int) ($meta['call_id'] ?? 0);
        if ($callId <= 0) {
            return;
        }

        $call = V2Call::query()->find($callId);
        if (!$call) {
            return;
        }

        $callMeta = is_array($call->meta) ? $call->meta : [];
        unset($callMeta['launch_error'], $callMeta['launch_error_user'], $callMeta['launch_pending_at'], $callMeta['launch_conversation_id']);

        $call->forceFill([
            'conversation_id' => $this->conversationId,
            'pending_message' => null,
            'scheduled_send_at' => null,
            'meta' => $callMeta,
        ])->save();

        $body = trim((string) $message->body);
        if ($body !== '') {
            app(CallOrchestrationService::class)->appendConversation($call, 'user', $body);
        }
    }
}

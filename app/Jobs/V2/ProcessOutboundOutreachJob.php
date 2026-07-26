<?php

namespace App\Jobs\V2;

use App\Models\V2Call;
use App\Models\V2Conversation;
use App\Models\V2Message;
use App\V2\Campaign\CampaignLinkedInGuard;
use App\V2\Integrations\ProviderManager;
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
            'message' => $providerManager->messaging($providerKey)->sendMessage(
                (string) ($this->payload['chat_id'] ?? ''),
                array_filter([
                    'text' => (string) ($this->payload['text'] ?? ''),
                    'account_id' => $payload['account_id'] ?? null,
                ]),
                $context
            ),
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

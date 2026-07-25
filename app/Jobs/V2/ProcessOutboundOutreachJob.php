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
use Illuminate\Bus\Queueable;
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

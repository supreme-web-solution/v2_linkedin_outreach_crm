<?php

namespace App\V2\Services;

use App\Jobs\V2\ProcessOutboundOutreachJob;
use App\Models\User;
use App\Models\V2AutoResponse;
use App\Models\V2Conversation;
use App\Models\V2IntegrationAccount;
use Illuminate\Support\Arr;

class AutoResponseService
{
    public function matchRule(int $userId, int $organizationId, string $inboundBody): ?V2AutoResponse
    {
        $normalized = strtolower(trim($inboundBody));
        if ($normalized === '') {
            return null;
        }

        $rules = V2AutoResponse::query()
            ->where('user_id', $userId)
            ->where('organization_id', $organizationId)
            ->where('enabled', true)
            ->orderByDesc('id')
            ->get();

        foreach ($rules as $rule) {
            if ($this->matches($rule, $normalized, $inboundBody)) {
                return $rule;
            }
        }

        return null;
    }

    public function matches(V2AutoResponse $rule, string $normalizedBody, string $rawBody): bool
    {
        $type = strtolower((string) ($rule->message_type ?? 'contains'));
        $keywords = trim((string) ($rule->message_keywords ?? ''));

        return match ($type) {
            'exact' => $keywords !== '' && strtolower(trim($keywords)) === $normalizedBody,
            'starts_with' => $keywords !== '' && str_starts_with($normalizedBody, strtolower($keywords)),
            'regex' => $keywords !== '' && @preg_match('/'.$keywords.'/i', $rawBody) === 1,
            'any', 'all' => true,
            default => $keywords === '' || str_contains($normalizedBody, strtolower($keywords)),
        };
    }

    public function handleInbound(V2Conversation $conversation, string $inboundBody, int $userId, int $organizationId): bool
    {
        if (!$conversation->provider_chat_id) {
            return false;
        }

        $rule = $this->matchRule($userId, $organizationId, $inboundBody);
        if (!$rule) {
            return false;
        }

        $this->sendReply($conversation, (string) $rule->message_body, $userId, $organizationId, [
            'auto_response_id' => $rule->id,
        ]);

        return true;
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function sendReply(
        V2Conversation $conversation,
        string $text,
        int $userId,
        int $organizationId,
        array $meta = []
    ): void {
        $text = trim($text);
        if ($text === '' || ! $conversation->provider_chat_id) {
            return;
        }

        if ($conversation->isInboxThread()) {
            $user = User::query()->find($userId);
            if ($user) {
                app(UnifiedInboxService::class)->sendMessage($user, $conversation, $text);

                return;
            }
        }

        $persistence = app(OutreachPersistenceService::class);
        $message = $persistence->createOutboundMessage(
            $conversation->id,
            $text,
            'message',
            ['chat_id' => $conversation->provider_chat_id] + $meta
        );

        $payload = [
            'chat_id' => $conversation->provider_chat_id,
            'text' => $text,
        ];

        $provider = (string) $conversation->provider;
        if ($provider !== '' && $provider !== 'linkedin') {
            $accountId = V2IntegrationAccount::activeUnipileAccountIdForProvider($userId, $provider);
            if ($accountId) {
                $payload['_unipile_account_id'] = $accountId;
                $payload['account_id'] = $accountId;
            }
        }

        ProcessOutboundOutreachJob::dispatch(
            'message',
            $userId,
            $organizationId,
            $conversation->id,
            $message->id,
            $payload
        );
    }
}

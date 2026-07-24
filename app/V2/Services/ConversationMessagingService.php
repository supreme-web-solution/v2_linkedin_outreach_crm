<?php

namespace App\V2\Services;

use App\Jobs\V2\ProcessOutboundOutreachJob;
use App\Models\User;
use App\Models\V2Conversation;
use App\Models\V2IntegrationAccount;

class ConversationMessagingService
{
    public function __construct(private readonly OutreachPersistenceService $persistence)
    {
    }

    public function sendMessage(User $user, V2Conversation $conversation, string $text): void
    {
        $text = trim($text);
        if ($text === '') {
            throw new \InvalidArgumentException('Message cannot be empty.');
        }

        if (!$conversation->provider_chat_id) {
            throw new \RuntimeException('Conversation is not linked to a Unipile chat.');
        }

        if (!V2IntegrationAccount::activeUnipileAccountId($user->id)) {
            throw new \RuntimeException('Connect LinkedIn via Integrations or Social Accounts first.');
        }

        $organizationId = (int) ($user->current_organization_id ?? 0);
        if ($organizationId <= 0) {
            throw new \RuntimeException('No workspace selected.');
        }

        $message = $this->persistence->createOutboundMessage(
            $conversation->id,
            $text,
            'message',
            ['chat_id' => $conversation->provider_chat_id, 'source' => 'web_conversations']
        );

        ProcessOutboundOutreachJob::dispatch(
            'message',
            $user->id,
            $organizationId,
            $conversation->id,
            $message->id,
            [
                'chat_id' => $conversation->provider_chat_id,
                'text' => $text,
            ]
        );
    }
}

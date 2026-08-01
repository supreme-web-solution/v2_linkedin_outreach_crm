<?php

namespace App\V2\Outreach;

use Illuminate\Support\Arr;

/**
 * Extract delivery proof from Unipile send/startChat/email responses.
 */
class OutreachSendProof
{
    /**
     * @param  array<string, mixed>  $response
     * @return array{chat_id: string, provider_message_id: string}
     */
    public static function fromResponse(array $response): array
    {
        $explicitChatId = trim((string) (
            Arr::get($response, 'chat_id')
            ?? Arr::get($response, 'data.chat_id')
            ?? ''
        ));

        $rawId = trim((string) (
            Arr::get($response, 'id')
            ?? Arr::get($response, 'data.id')
            ?? ''
        ));

        $object = strtolower(trim((string) (
            Arr::get($response, 'object')
            ?? Arr::get($response, 'type')
            ?? Arr::get($response, 'data.object')
            ?? ''
        )));

        $messageId = trim((string) (
            Arr::get($response, 'message_id')
            ?? Arr::get($response, 'data.message_id')
            ?? Arr::get($response, 'data.message.id')
            ?? Arr::get($response, 'message.id')
            ?? Arr::get($response, 'tracking_id')
            ?? Arr::get($response, 'data.tracking_id')
            ?? ''
        ));

        if ($explicitChatId !== '') {
            $chatId = $explicitChatId;
            if ($messageId === '' && $rawId !== '' && $rawId !== $explicitChatId) {
                $messageId = $rawId;
            }
        } elseif (in_array($object, ['message', 'chat_message'], true)) {
            $chatId = '';
            if ($messageId === '' && $rawId !== '') {
                $messageId = $rawId;
            }
        } else {
            $chatId = $rawId;
        }

        if ($messageId === '' && $chatId !== '' && str_starts_with($chatId, 'msg_')) {
            $messageId = $chatId;
            $chatId = '';
        }

        return [
            'chat_id' => $chatId,
            'provider_message_id' => $messageId,
        ];
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public static function hasDeliveryProof(array $response): bool
    {
        $proof = self::fromResponse($response);

        return $proof['chat_id'] !== '' || $proof['provider_message_id'] !== '';
    }

    public static function isOutboundSendAction(string $action): bool
    {
        return in_array($action, ['send_message', 'send_email', 'send_invite'], true);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    public static function nodeIsOutboundSend(array $node): bool
    {
        if (($node['type'] ?? 'action') !== 'action') {
            return false;
        }

        return self::isOutboundSendAction((string) ($node['action'] ?? ''));
    }
}

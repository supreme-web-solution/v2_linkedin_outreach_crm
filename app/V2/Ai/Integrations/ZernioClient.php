<?php

namespace App\V2\Ai\Integrations;

use App\Models\AiChannelIdentity;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ZernioClient
{
    private const DEFAULT_MAX_MESSAGE_LENGTH = 1024;

    public function configured(): bool
    {
        return filled($this->apiKey())
            && filled($this->baseUrl())
            && (filled($this->defaultAccountId()) || filled(config('socifusion_ai.zernio.from_number')));
    }

    public function apiKey(): string
    {
        return (string) config('socifusion_ai.zernio.api_key', '');
    }

    public function baseUrl(): string
    {
        return rtrim((string) config('socifusion_ai.zernio.base_url', 'https://api.zernio.com'), '/');
    }

    public function defaultAccountId(): string
    {
        return (string) config('socifusion_ai.zernio.account_id', '');
    }

    public function webhookSecret(): string
    {
        return (string) config('socifusion_ai.zernio.webhook_secret', '');
    }

    public function computeSignature(string $rawBody): string
    {
        return hash_hmac('sha256', $rawBody, $this->webhookSecret());
    }

    public function verifyWebhookSignature(string $rawBody, ?string $provided): bool
    {
        $secret = $this->webhookSecret();

        if ($secret === '') {
            return app()->environment('local');
        }

        if ($provided === null || $provided === '') {
            return false;
        }

        return hash_equals($this->computeSignature($rawBody), $provided);
    }

    /**
     * Legacy simple shared-secret header (local/dev fallback).
     */
    public function verifyLegacySecret(?string $provided): bool
    {
        $secret = $this->webhookSecret();

        if ($secret === '') {
            return app()->environment('local');
        }

        return hash_equals($secret, (string) $provided);
    }

    /**
     * @return array{
     *     event_id: string,
     *     event: string,
     *     message_id: ?string,
     *     text: string,
     *     from: string,
     *     conversation_id: ?string,
     *     account_id: ?string,
     *     is_inbound_message: bool,
     *     media: ?array{type:string,url:string,mime:?string}
     * }
     */
    public function parseWebhookEvent(array $payload): array
    {
        $event = (string) ($payload['event'] ?? '');
        $eventId = (string) ($payload['id'] ?? $payload['event_id'] ?? '');

        if ($event === '' && isset($payload['from'], $payload['text'])) {
            return [
                'event_id' => $eventId,
                'event' => 'legacy.inbound',
                'message_id' => isset($payload['message_id']) ? (string) $payload['message_id'] : null,
                'text' => trim((string) ($payload['text'] ?? '')),
                'from' => (string) ($payload['from'] ?? ''),
                'conversation_id' => null,
                'account_id' => null,
                'is_inbound_message' => true,
                'media' => $this->extractMedia(is_array($payload['message'] ?? null) ? $payload['message'] : $payload),
            ];
        }

        $message = is_array($payload['message'] ?? null) ? $payload['message'] : [];
        $conversation = is_array($payload['conversation'] ?? null) ? $payload['conversation'] : [];
        $account = is_array($payload['account'] ?? null) ? $payload['account'] : [];
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];

        $text = trim((string) (
            $message['text']
            ?? $message['body']
            ?? ''
        ));

        $text = $this->resolveInteractiveText($text, $message, $metadata);

        $from = $this->extractSenderPhone($message, $conversation);

        return [
            'event_id' => $eventId,
            'event' => $event,
            'message_id' => isset($message['id']) ? (string) $message['id'] : (isset($message['platformMessageId']) ? (string) $message['platformMessageId'] : null),
            'text' => $text,
            'from' => $from,
            'conversation_id' => isset($conversation['id']) ? (string) $conversation['id'] : null,
            'account_id' => isset($account['id']) ? (string) $account['id'] : null,
            'is_inbound_message' => $event === 'message.received',
            'media' => $this->extractMedia($message),
        ];
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array{type:string,url:string,mime:?string}|null
     */
    private function extractMedia(array $message): ?array
    {
        $candidates = [];

        foreach ((array) ($message['attachments'] ?? []) as $attachment) {
            if (! is_array($attachment)) {
                continue;
            }
            $candidates[] = $attachment;
        }

        if (isset($message['media']) && is_array($message['media'])) {
            $candidates[] = $message['media'];
        }

        foreach ($candidates as $attachment) {
            $type = Str::lower(trim((string) (
                $attachment['type']
                ?? $attachment['mediaType']
                ?? $attachment['kind']
                ?? 'image'
            )));

            if ($type !== '' && ! str_contains($type, 'image') && ! in_array($type, ['photo', 'picture', 'media', 'file'], true)) {
                continue;
            }

            $url = trim((string) (
                $attachment['url']
                ?? $attachment['mediaUrl']
                ?? $attachment['link']
                ?? $attachment['secureUrl']
                ?? data_get($attachment, 'payload.url')
                ?? ''
            ));

            if ($url === '') {
                continue;
            }

            return [
                'type' => 'image',
                'url' => $url,
                'mime' => isset($attachment['mimeType']) ? (string) $attachment['mimeType'] : null,
            ];
        }

        foreach ([
            data_get($message, 'image.url'),
            data_get($message, 'imageUrl'),
            data_get($message, 'mediaUrl'),
        ] as $url) {
            if (is_string($url) && trim($url) !== '') {
                return [
                    'type' => 'image',
                    'url' => trim($url),
                    'mime' => null,
                ];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array<string, mixed>  $conversation
     */
    private function extractSenderPhone(array $message, array $conversation): string
    {
        $candidates = [
            data_get($message, 'sender.phoneNumber'),
            data_get($message, 'sender.phone'),
            data_get($conversation, 'contact.phone'),
            data_get($conversation, 'contact.phoneNumber'),
            data_get($conversation, 'participantUsername'),
            data_get($conversation, 'participant.phone'),
            data_get($conversation, 'participantId'),
            data_get($message, 'from'),
            data_get($conversation, 'externalId'),
            data_get($conversation, 'platformConversationId'),
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }

            $digits = preg_replace('/\D+/', '', $candidate) ?? '';
            if ($digits !== '') {
                return '+'.$digits;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array<string, mixed>  $metadata
     */
    private function resolveInteractiveText(string $text, array $message, array $metadata): string
    {
        $payload = $this->extractButtonPayload($message, $metadata);

        if ($payload !== '') {
            return $this->normalizeControlText($payload);
        }

        if ($text !== '') {
            return $this->normalizeControlText($text);
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array<string, mixed>  $metadata
     */
    private function extractButtonPayload(array $message, array $metadata): string
    {
        foreach ([
            data_get($message, 'postback.payload'),
            data_get($message, 'postback'),
            data_get($message, 'button.payload'),
            data_get($message, 'buttonPayload'),
            data_get($message, 'payload'),
            data_get($message, 'interactive.button_reply.id'),
            data_get($message, 'interactive.list_reply.id'),
            data_get($message, 'interactive.payload'),
            data_get($message, 'attachments.0.payload'),
            data_get($message, 'attachments.0.button.payload'),
            data_get($metadata, 'button_payload'),
            data_get($metadata, 'interactiveId'),
            data_get($metadata, 'interactive_id'),
            data_get($metadata, 'quick_reply_payload'),
            data_get($metadata, 'quickReplyPayload'),
        ] as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }

            if ($this->looksLikeControlPayload($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    private function looksLikeControlPayload(string $value): bool
    {
        return (bool) preg_match('/^(LAUNCH|APPROVE|REJECT|REVIEW|ACTIVATE|PAUSE)[_\s#-]*\d+$/i', $value);
    }

    private function normalizeControlText(string $text): string
    {
        $trimmed = trim($text);

        $normalized = (string) preg_replace_callback(
            '/^(LAUNCH|APPROVE|REJECT|REVIEW|ACTIVATE|PAUSE)_(\d+)$/i',
            fn (array $m) => strtoupper($m[1]).' '.$m[2],
            $trimmed,
        );

        return (string) preg_replace_callback(
            '/^(LAUNCH|APPROVE|REJECT|REVIEW|ACTIVATE|PAUSE)\s*#\s*(\d+)$/i',
            fn (array $m) => strtoupper($m[1]).' '.$m[2],
            $normalized,
        );
    }

    public function sendReply(AiChannelIdentity $identity, string $body, ?array $buttons = null): bool
    {
        return $this->sendToIdentity($identity, $body, $buttons);
    }

    /**
     * @param  list<array{type:string,title:string,payload:string}>|null  $buttons
     */
    public function sendToIdentity(AiChannelIdentity $identity, string $body, ?array $buttons = null): bool
    {
        $context = $this->inboxContextFromIdentity($identity);

        if ($context !== null && $this->useInboxApi()) {
            return $this->sendInboxMessage($context['conversation_id'], $context['account_id'], $body, $buttons);
        }

        return $this->sendLegacyText($identity->external_id, $body);
    }

    public function sendTypingIndicator(AiChannelIdentity $identity): bool
    {
        if (! $this->useInboxApi()) {
            return false;
        }

        $context = $this->inboxContextFromIdentity($identity);
        if ($context === null) {
            Log::debug('[Zernio] skip typing indicator — missing inbox context', [
                'identity_id' => $identity->id,
            ]);

            return false;
        }

        return $this->postTypingIndicator($context['conversation_id'], $context['account_id']);
    }

    /**
     * @return array{conversation_id:string, account_id:string}|null
     */
    private function inboxContextFromIdentity(AiChannelIdentity $identity): ?array
    {
        $meta = is_array($identity->meta) ? $identity->meta : [];
        $conversationId = (string) ($meta['zernio_conversation_id'] ?? '');
        $accountId = (string) ($meta['zernio_account_id'] ?? $this->defaultAccountId());

        if ($conversationId === '' || $accountId === '') {
            return null;
        }

        return [
            'conversation_id' => $conversationId,
            'account_id' => $accountId,
        ];
    }

    /**
     * @param  list<array{type:string,title:string,payload:string}>|null  $buttons
     */
    public function sendInboxMessage(string $conversationId, string $accountId, string $body, ?array $buttons = null): bool
    {
        $chunks = $this->chunkMessage($body);
        if ($chunks === []) {
            Log::warning('[Zernio] skip inbox send — empty message body', [
                'conversation_id' => $conversationId,
            ]);

            return false;
        }

        $ok = true;
        $lastIndex = array_key_last($chunks);

        foreach ($chunks as $index => $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }

            $payload = [
                'accountId' => $accountId,
                'message' => $chunk,
            ];

            if ($buttons !== null && $index === $lastIndex) {
                $payload['buttons'] = $buttons;
            }

            if (! $this->postInboxMessage($conversationId, $payload)) {
                $ok = false;
            }
        }

        return $ok;
    }

    /**
     * @return list<array{type:string,title:string,payload:string}>
     */
    public function approvalButtons(int $approvalId): array
    {
        return [
            [
                'type' => 'postback',
                'title' => "Launch #{$approvalId}",
                'payload' => 'LAUNCH_'.$approvalId,
            ],
            [
                'type' => 'postback',
                'title' => "Reject #{$approvalId}",
                'payload' => 'REJECT_'.$approvalId,
            ],
        ];
    }

    public function sendText(string $to, string $body): bool
    {
        return $this->sendLegacyText($to, $body);
    }

    private function sendLegacyText(string $to, string $body): bool
    {
        $chunks = $this->chunkMessage($body);

        if (! $this->configured()) {
            foreach ($chunks as $chunk) {
                Log::info('[Zernio] stub send (not configured)', [
                    'to' => $to,
                    'body' => $chunk,
                ]);
            }

            return true;
        }

        if ($this->useInboxApi()) {
            Log::warning('[Zernio] missing inbox conversation context for send', ['to' => $to]);

            return false;
        }

        $ok = true;

        foreach ($chunks as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }

            if (! $this->postLegacyMessage($to, $chunk)) {
                $ok = false;
            }
        }

        return $ok;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postInboxMessage(string $conversationId, array $payload): bool
    {
        if (! $this->configured()) {
            Log::info('[Zernio] stub inbox send', [
                'conversation_id' => $conversationId,
                'payload' => $payload,
            ]);

            return true;
        }

        $response = $this->http()
            ->post("/v1/inbox/conversations/{$conversationId}/messages", $payload);

        return $this->logResponse($response, 'inbox send', [
            'conversation_id' => $conversationId,
        ]);
    }

    private function postLegacyMessage(string $to, string $body): bool
    {
        $response = $this->http()->post('/messages', [
            'from' => config('socifusion_ai.zernio.from_number'),
            'to' => $to,
            'text' => $body,
        ]);

        return $this->logResponse($response, 'legacy send', ['to' => $to]);
    }

    private function postTypingIndicator(string $conversationId, string $accountId): bool
    {
        if (! $this->configured()) {
            Log::info('[Zernio] stub typing indicator', [
                'conversation_id' => $conversationId,
                'account_id' => $accountId,
            ]);

            return true;
        }

        $response = $this->http()->post("/v1/inbox/conversations/{$conversationId}/typing", [
            'accountId' => $accountId,
        ]);

        return $this->logResponse($response, 'typing indicator', [
            'conversation_id' => $conversationId,
        ]);
    }

    private function http()
    {
        return Http::baseUrl($this->baseUrl())
            ->withToken($this->apiKey())
            ->acceptJson()
            ->timeout(20);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logResponse(Response $response, string $action, array $context): bool
    {
        if ($response->successful()) {
            return true;
        }

        Log::warning('[Zernio] '.$action.' failed', array_merge($context, [
            'status' => $response->status(),
            'body' => $response->body(),
        ]));

        return false;
    }

    private function useInboxApi(): bool
    {
        return (bool) config('socifusion_ai.zernio.use_inbox_api', true);
    }

    /**
     * @return list<string>
     */
    private function chunkMessage(string $body): array
    {
        $max = $this->maxMessageLength();
        $body = trim($body);
        if ($body === '') {
            return [];
        }

        if ($this->messageLength($body) <= $max) {
            return [$body];
        }

        $parts = preg_split("/\n{2,}/", $body) ?: [$body];
        $chunks = [];
        $current = '';

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $candidate = $current === '' ? $part : $current."\n\n".$part;
            if ($this->messageLength($candidate) <= $max) {
                $current = $candidate;

                continue;
            }

            if ($current !== '') {
                $chunks[] = $current;
                $current = '';
            }

            if ($this->messageLength($part) <= $max) {
                $current = $part;

                continue;
            }

            foreach ($this->splitByLength($part, $max) as $slice) {
                $chunks[] = $slice;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks !== [] ? $chunks : [Str::limit($body, $max, '…')];
    }

    private function maxMessageLength(): int
    {
        $configured = (int) config('socifusion_ai.zernio.max_message_length', self::DEFAULT_MAX_MESSAGE_LENGTH);

        return max(1, min($configured, self::DEFAULT_MAX_MESSAGE_LENGTH));
    }

    private function messageLength(string $text): int
    {
        return mb_strlen($text);
    }

    /**
     * @return list<string>
     */
    private function splitByLength(string $text, int $max): array
    {
        if ($this->messageLength($text) <= $max) {
            return [$text];
        }

        $chunks = [];
        $offset = 0;
        $length = $this->messageLength($text);

        while ($offset < $length) {
            $chunks[] = mb_substr($text, $offset, $max);
            $offset += $max;
        }

        return $chunks;
    }
}

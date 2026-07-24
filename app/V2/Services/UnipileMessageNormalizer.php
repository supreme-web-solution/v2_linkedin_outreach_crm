<?php

namespace App\V2\Services;

use Illuminate\Support\Arr;

class UnipileMessageNormalizer
{
    /**
     * Event types 1/2 = reaction events that must not appear as chat bubbles.
     */
    public function shouldSkipAsChatMessage(array $item): bool
    {
        return $this->resolveReactionEvent($item) !== null;
    }

    public function isReactionAnnouncementText(string $text): bool
    {
        return $this->parseReactionAnnouncementText($text) !== null;
    }

    /**
     * @return array{value: string, sender_id: string|null, target_provider_message_id: string|null, is_sender: bool, skip_only?: bool}|null
     */
    public function resolveReactionEvent(array $item): ?array
    {
        $text = trim((string) ($item['text'] ?? ''));

        if ($this->isTruthy($item['hidden'] ?? false)
            || $this->isTruthy($item['is_event'] ?? false)
            || in_array((int) ($item['event_type'] ?? 0), [1, 2], true)) {
            return $this->buildReactionFromItem($item);
        }

        $parsed = $this->parseReactionAnnouncementText($text);
        if ($parsed === null) {
            return null;
        }

        return [
            'value' => $parsed['value'],
            'sender_id' => $parsed['sender_id'],
            'target_provider_message_id' => $this->extractReactionTargetMessageId($item),
            'is_sender' => $this->isTruthy($item['is_sender'] ?? false),
        ];
    }

    /**
     * @return array{value: string, sender_id: string|null}|null
     */
    public function parseReactionAnnouncementText(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        if (preg_match('/^\{\{([^}]+)\}\}\s+reacted\s+(.+)$/u', $text, $matches) === 1) {
            return [
                'sender_id' => trim($matches[1]),
                'value' => trim($matches[2]),
            ];
        }

        if (preg_match('/^(.+?)\s+reacted\s+(.+)$/u', $text, $matches) === 1) {
            $sender = trim($matches[1]);
            if (str_contains($sender, '@') || mb_strlen($sender) <= 80) {
                return [
                    'sender_id' => $sender,
                    'value' => trim($matches[2]),
                ];
            }
        }

        return null;
    }

    public function extractReactionTargetMessageId(array $item): ?string
    {
        foreach ([
            Arr::get($item, 'parent'),
            Arr::get($item, 'quoted.message_id'),
            Arr::get($item, 'quoted.id'),
            Arr::get($item, 'referenced_message_id'),
            Arr::get($item, 'reaction_to_message_id'),
            Arr::get($item, 'data.parent'),
            Arr::get($item, 'data.quoted.message_id'),
        ] as $id) {
            $id = trim((string) ($id ?? ''));
            if ($id !== '') {
                return $id;
            }
        }

        return null;
    }

    /**
     * @return array{value: string, sender_id: string|null, target_provider_message_id: string|null, is_sender: bool, skip_only?: bool}
     */
    private function buildReactionFromItem(array $item): array
    {
        $parsed = $this->parseReactionAnnouncementText(trim((string) ($item['text'] ?? '')));
        $value = $parsed['value'] ?? trim((string) (
            Arr::get($item, 'reaction')
            ?? Arr::get($item, 'data.reaction')
            ?? ''
        ));

        if ($value === '') {
            return ['skip_only' => true, 'value' => '', 'sender_id' => null, 'target_provider_message_id' => null, 'is_sender' => false];
        }

        return [
            'value' => $value,
            'sender_id' => $parsed['sender_id'] ?? trim((string) (
                Arr::get($item, 'sender_id')
                ?? Arr::get($item, 'data.sender_id')
                ?? ''
            )) ?: null,
            'target_provider_message_id' => $this->extractReactionTargetMessageId($item),
            'is_sender' => $this->isTruthy($item['is_sender'] ?? false),
        ];
    }

    private function isTruthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }

    /**
     * @return list<array{id: string, type: string|null, mimetype: string|null, filename: string|null, unavailable: bool}>
     */
    public function extractAttachments(array $item): array
    {
        $raw = Arr::get($item, 'attachments', []);
        if (! is_array($raw)) {
            return [];
        }

        $attachments = [];
        foreach ($raw as $attachment) {
            if (! is_array($attachment)) {
                continue;
            }

            $id = trim((string) ($attachment['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $attachments[] = [
                'id' => $id,
                'type' => isset($attachment['type']) ? (string) $attachment['type'] : null,
                'mimetype' => isset($attachment['mimetype']) ? (string) $attachment['mimetype'] : null,
                'filename' => $this->guessFilename($attachment),
                'unavailable' => (bool) ($attachment['unavailable'] ?? false),
            ];
        }

        return $attachments;
    }

    /**
     * @return list<array{value: string, sender_id: string|null, is_sender: bool}>
     */
    public function extractReactions(array $item): array
    {
        $raw = Arr::get($item, 'reactions', []);
        if (! is_array($raw)) {
            return [];
        }

        $reactions = [];
        foreach ($raw as $reaction) {
            if (! is_array($reaction)) {
                continue;
            }

            $value = trim((string) ($reaction['value'] ?? ''));
            if ($value === '') {
                continue;
            }

            $reactions[] = [
                'value' => $value,
                'sender_id' => isset($reaction['sender_id']) ? (string) $reaction['sender_id'] : null,
                'is_sender' => (bool) ($reaction['is_sender'] ?? false),
            ];
        }

        return $this->uniqueReactions($reactions);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{message_id: string, value: string, sender_id: string|null, is_sender: bool}|null
     */
    public function extractReactionEvent(array $payload): ?array
    {
        $messageId = trim((string) (
            Arr::get($payload, 'data.message_id')
            ?? Arr::get($payload, 'message_id')
            ?? Arr::get($payload, 'data.referenced_message_id')
            ?? Arr::get($payload, 'referenced_message_id')
            ?? ''
        ));

        $value = trim((string) (
            Arr::get($payload, 'data.reaction')
            ?? Arr::get($payload, 'reaction')
            ?? ''
        ));

        if ($messageId === '' || $value === '') {
            return null;
        }

        $senderId = (string) (
            Arr::get($payload, 'data.reaction_sender.attendee_provider_id')
            ?? Arr::get($payload, 'reaction_sender.attendee_provider_id')
            ?? Arr::get($payload, 'data.sender_id')
            ?? Arr::get($payload, 'sender_id')
            ?? ''
        );

        $isSender = (bool) (
            Arr::get($payload, 'data.reaction_sender.is_sender')
            ?? Arr::get($payload, 'reaction_sender.is_sender')
            ?? Arr::get($payload, 'data.is_sender')
            ?? Arr::get($payload, 'is_sender')
            ?? false
        );

        return [
            'message_id' => $messageId,
            'value' => $value,
            'sender_id' => $senderId !== '' ? $senderId : null,
            'is_sender' => $isSender,
        ];
    }

    public function hasDisplayableContent(array $item): bool
    {
        $text = trim((string) ($item['text'] ?? ''));
        if ($text !== '') {
            return true;
        }

        return $this->extractAttachments($item) !== [];
    }

    /**
     * @param  list<array{value: string, sender_id: string|null, is_sender: bool}>  $existing
     * @param  list<array{value: string, sender_id: string|null, is_sender: bool}>  $incoming
     * @return list<array{value: string, sender_id: string|null, is_sender: bool}>
     */
    public function mergeReactions(array $existing, array $incoming): array
    {
        return $this->uniqueReactions(array_merge($existing, $incoming));
    }

    /**
     * @param  list<array{value: string, sender_id: string|null, is_sender: bool}>  $reactions
     * @return list<array{value: string, sender_id: string|null, is_sender: bool}>
     */
    private function uniqueReactions(array $reactions): array
    {
        $seen = [];
        $unique = [];

        foreach ($reactions as $reaction) {
            $key = ($reaction['sender_id'] ?? '').'|'.($reaction['value'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $reaction;
        }

        return $unique;
    }

    /**
     * @param  array<string, mixed>  $attachment
     */
    private function guessFilename(array $attachment): ?string
    {
        foreach (['filename', 'name', 'file_name'] as $key) {
            $value = trim((string) ($attachment[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        $type = (string) ($attachment['type'] ?? '');
        $mime = (string) ($attachment['mimetype'] ?? '');

        return match (true) {
            str_starts_with($mime, 'image/') => 'image.'.($mime === 'image/png' ? 'png' : 'jpg'),
            str_starts_with($mime, 'video/') => 'video.mp4',
            str_starts_with($mime, 'audio/') => 'audio.mp3',
            $type === 'img' => 'image.jpg',
            $type === 'video' => 'video.mp4',
            $type === 'audio' => 'audio.mp3',
            default => 'file',
        };
    }
}

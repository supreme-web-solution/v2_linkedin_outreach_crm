<?php

namespace App\V2\Ai\Support;

use Illuminate\Support\Str;

/**
 * Short, link-preview-safe text for WhatsApp mirrors of Command Center messages.
 */
final class WhatsAppNotificationFormatter
{
    public static function plain(string $markdown, int $maxLength = 900): string
    {
        $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '$1', $markdown) ?? $markdown;
        $text = str_replace(['**', '__', '`'], '', $text);
        $text = preg_replace('/^>\s*/m', '', $text) ?? $text;
        $text = preg_replace('/https?:\/\/[^\s]+/', '', $text) ?? $text;
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');

        return Str::limit($text, $maxLength, '…');
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function compactThreadLine(array $row, int $previewLimit = 72): string
    {
        $name = (string) ($row['prospect_name'] ?? 'Prospect');
        $email = trim((string) ($row['prospect_email'] ?? ''));
        $channel = (string) ($row['channel_label'] ?? $row['channel'] ?? 'inbox');
        $preview = trim((string) ($row['preview'] ?? ''));
        $preview = preg_replace('/^URL:\s*/m', '', $preview) ?? $preview;
        $preview = Str::limit(trim(preg_replace('/\s+/', ' ', $preview) ?? ''), $previewLimit, '…');

        $who = $email !== '' ? "{$name} ({$email})" : $name;
        $hot = ($row['priority'] ?? '') === 'hot' ? '🔥 ' : '';

        return $hot."{$channel}: {$who}".($preview !== '' ? " — {$preview}" : '');
    }
}

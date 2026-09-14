<?php

namespace App\V2\Ai\Services;

use App\Models\AiConversation;
use App\Models\AiMessage;
use Illuminate\Support\Str;

/**
 * Large chat pastes (ICP essays, docs, transcripts) must not vanish after one turn.
 * Full text stays on AiMessage.content; planners/history get a durable digest.
 */
class LongPasteDigestService
{
    /** Messages at or above this length are treated as long pastes. */
    public const LARGE_CHARS = 1500;

    /** Cap for the current-turn planner payload (full paste still stored on the message). */
    public const PLANNER_CHARS = 12000;

    /** Digest size kept in thread history / workstream memory. */
    public const DIGEST_CHARS = 1600;

    public function isLarge(string $text): bool
    {
        return mb_strlen(trim($text)) >= self::LARGE_CHARS;
    }

    /**
     * Extractive, domain-agnostic digest — headings, bullets, labeled lines, opener.
     * No industry hardcoding; no LLM required.
     */
    public function digest(string $text): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($text === '') {
            return '';
        }
        if (! $this->isLarge($text)) {
            return $text;
        }

        $lines = preg_split('/\n+/', $text) ?: [];
        $keep = [];
        $sawBody = false;

        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '') {
                continue;
            }

            // Keep the owner's instruction line(s) before the pasted body.
            if (! $sawBody && ! preg_match('/^#{1,6}\s/', $t) && count($keep) < 3) {
                $keep[] = Str::limit($t, 220, '…');
                if (mb_strlen($t) > 80 || str_contains(Str::lower($t), 'icp') || str_contains($t, ':')) {
                    // still part of preamble
                }
            }

            if (preg_match('/^#{1,6}\s+\S/u', $t)
                || preg_match('/^\d+[\.\)]\s+\S/u', $t)
                || preg_match('/^[-*•]\s+\S/u', $t)
                || preg_match('/^[A-Za-z][A-Za-z0-9 &\-\/]{1,40}:\s+\S/u', $t)
            ) {
                $sawBody = true;
                $keep[] = Str::limit($t, 180, '…');
            }

            if (count($keep) >= 40) {
                break;
            }
        }

        if (count($keep) >= 3) {
            $digest = implode("\n", $keep);
        } else {
            // Unstructured wall of text — keep a usable head, not a random mid-slice of noise.
            $digest = Str::limit(preg_replace('/\s+/', ' ', $text) ?? $text, self::DIGEST_CHARS - 80, '');
        }

        $digest = Str::limit(trim($digest), self::DIGEST_CHARS - 48, '');
        $chars = mb_strlen($text);

        return $digest."\n…[long paste retained in chat · {$chars} chars]";
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function enrichUserMeta(array $meta, string $content): array
    {
        if (! $this->isLarge($content)) {
            return $meta;
        }

        $meta['long_paste'] = true;
        $meta['paste_chars'] = mb_strlen(trim($content));
        $meta['paste_digest'] = $this->digest($content);

        return $meta;
    }

    /**
     * Content for planner/agent thread history — digest for long pastes, short truncate otherwise.
     */
    public function forThread(?array $meta, string $content, int $shortLimit = 600): string
    {
        $content = trim($content);
        if ($content === '') {
            return '';
        }

        if (! $this->isLarge($content)) {
            return Str::limit($content, $shortLimit, '');
        }

        $digest = trim((string) ($meta['paste_digest'] ?? ''));
        if ($digest === '') {
            $digest = $this->digest($content);
        }

        return $digest;
    }

    public function forThreadMessage(AiMessage $message, int $shortLimit = 600): string
    {
        $meta = is_array($message->meta) ? $message->meta : [];

        return $this->forThread($meta, (string) $message->content, $shortLimit);
    }

    /**
     * Current-turn planner payload: enough raw text to act, plus digest for continuity.
     *
     * @return array{
     *     user_message:string,
     *     long_paste:bool,
     *     paste_chars:int,
     *     paste_digest:?string
     * }
     */
    public function forCurrentPlanner(string $message): array
    {
        $message = trim($message);
        $chars = mb_strlen($message);
        if (! $this->isLarge($message)) {
            return [
                'user_message' => $message,
                'long_paste' => false,
                'paste_chars' => $chars,
                'paste_digest' => null,
            ];
        }

        return [
            'user_message' => Str::limit($message, self::PLANNER_CHARS, "\n…[truncated for planner; full text saved on this chat message]"),
            'long_paste' => true,
            'paste_chars' => $chars,
            'paste_digest' => $this->digest($message),
        ];
    }

    public function rememberOnWorkstream(AiConversation $conversation, string $message): void
    {
        if (! $this->isLarge($message)) {
            return;
        }

        app(WorkstreamMemoryService::class)->rememberFacts($conversation, [
            'owner_paste_digest' => $this->digest($message),
        ]);
    }
}

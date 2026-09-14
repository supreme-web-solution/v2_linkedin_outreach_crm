<?php

namespace App\V2\Ai\Support;

use Illuminate\Support\Str;

/**
 * Domain-agnostic email subject + body formatting for outbound sends.
 * Unipile/Gmail treat body as HTML — plain newlines alone collapse into one block.
 */
final class EmailOutboundFormat
{
    /**
     * Normalize AI body into a readable plain-text email (blank lines between paragraphs).
     */
    public static function formatPlainBody(string $body): string
    {
        $body = trim($body);
        if ($body === '') {
            return '';
        }

        // Normalize line endings; collapse 3+ newlines to 2.
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = preg_replace("/\n{3,}/", "\n\n", $body) ?? $body;

        // If the model returned one dense block, split into short paragraphs.
        if (! str_contains($body, "\n\n") && strlen($body) > 280) {
            $body = self::splitDenseBlock($body);
        }

        $parts = preg_split("/\n\s*\n/", $body) ?: [$body];
        $paras = [];
        foreach ($parts as $part) {
            $line = trim(preg_replace("/[ \t]+/", ' ', str_replace("\n", ' ', $part)) ?? '');
            if ($line !== '') {
                $paras[] = $line;
            }
        }

        if ($paras === []) {
            return '';
        }

        // Keep greeting on its own line when present.
        if (count($paras) >= 2 && preg_match('/^(hi|hello|hey)\b.{0,40},?\s*$/iu', $paras[0])) {
            // already separate
        } elseif (preg_match('/^((?:hi|hello|hey)\s[^,\n]{1,40},)\s+(.+)$/isu', $paras[0], $m)) {
            array_shift($paras);
            array_unshift($paras, trim($m[2]));
            array_unshift($paras, trim($m[1]));
        }

        return implode("\n\n", $paras);
    }

    /**
     * HTML body for providers that render email as HTML (Unipile → Gmail).
     */
    public static function toHtmlBody(string $plainBody): string
    {
        $plain = self::formatPlainBody($plainBody);
        if ($plain === '') {
            return '';
        }

        $paras = preg_split("/\n\s*\n/", $plain) ?: [$plain];
        $html = [];
        foreach ($paras as $para) {
            $para = trim($para);
            if ($para === '') {
                continue;
            }
            $html[] = '<p style="margin:0 0 1em 0;line-height:1.5;font-size:15px;">'
                .nl2br(e($para), false)
                .'</p>';
        }

        return implode("\n", $html);
    }

    /**
     * Subject that can convert: specific, short, not generic filler.
     */
    public static function normalizeSubject(string $subject, ?string $researchHint = null): string
    {
        $subject = trim(preg_replace('/\s+/', ' ', $subject) ?? '');
        $subject = trim($subject, " \t\"'`");
        $subject = preg_replace('/^(re|fwd|fw)\s*:\s*/i', '', $subject) ?? $subject;
        $subject = Str::limit($subject, 70, '');

        if ($subject === '' || self::isWeakSubject($subject)) {
            $fromResearch = self::subjectFromResearch($researchHint);
            if ($fromResearch !== null) {
                return $fromResearch;
            }
        }

        if ($subject === '' || self::isWeakSubject($subject)) {
            return 'Quick question about your workflow';
        }

        return $subject;
    }

    public static function isWeakSubject(string $subject): bool
    {
        $s = Str::lower(trim($subject));

        return in_array($s, [
            'quick note',
            'hello',
            'hi',
            'introduction',
            'following up',
            'follow up',
            'follow-up',
            'checking in',
            'touching base',
            'quick intro',
            'a quick note',
        ], true)
            || (bool) preg_match('/^(hi|hello|hey)\b/i', $s)
            || strlen($s) < 8;
    }

    private static function subjectFromResearch(?string $researchHint): ?string
    {
        $hint = trim((string) $researchHint);
        if ($hint === '') {
            return null;
        }

        if (preg_match('/Title:\s*(.+)/i', $hint, $m)) {
            $title = trim($m[1]);
            if ($title !== '' && strlen($title) >= 4) {
                return Str::limit($title.' — quick question', 70, '');
            }
        }

        // First meaningful line as a concrete hook.
        foreach (preg_split('/\n+/', $hint) ?: [] as $line) {
            $line = trim(preg_replace('/\s+/', ' ', $line) ?? '');
            if ($line === '' || preg_match('/^(URL|Title|AI research notes):/i', $line)) {
                continue;
            }
            $hook = Str::limit($line, 48, '');
            if (strlen($hook) >= 12) {
                return Str::limit('Re: '.$hook, 70, '');
            }
        }

        return null;
    }

    private static function splitDenseBlock(string $body): string
    {
        // Split after sentence endings when the next clause starts a new idea.
        $parts = preg_split('/(?<=[.!?])\s+(?=[A-Z“"])/u', $body) ?: [$body];
        if (count($parts) < 2) {
            return $body;
        }

        $paras = [];
        $buf = '';
        foreach ($parts as $sentence) {
            $sentence = trim($sentence);
            if ($sentence === '') {
                continue;
            }
            $next = $buf === '' ? $sentence : $buf.' '.$sentence;
            if (strlen($next) > 220 && $buf !== '') {
                $paras[] = $buf;
                $buf = $sentence;
            } else {
                $buf = $next;
            }
        }
        if ($buf !== '') {
            $paras[] = $buf;
        }

        // Prefer 3–5 paragraphs for cold email readability.
        if (count($paras) > 5) {
            $paras = array_merge(
                array_slice($paras, 0, 3),
                [implode(' ', array_slice($paras, 3))]
            );
        }

        return implode("\n\n", $paras);
    }
}

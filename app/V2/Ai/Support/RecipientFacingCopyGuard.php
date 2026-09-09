<?php

namespace App\V2\Ai\Support;

/**
 * Blocks internal planning text and unresolved placeholders from being sent to prospects.
 */
final class RecipientFacingCopyGuard
{
    /**
     * @return list<string> Human-readable problems (empty = ok to send)
     */
    public static function problems(string $text): array
    {
        $text = trim($text);
        $problems = [];

        if ($text === '') {
            $problems[] = 'Message is empty.';

            return $problems;
        }

        if (self::looksLikeInternalPlan($text)) {
            $problems[] = 'Message looks like an internal action plan, not a recipient-facing email/DM.';
        }

        if (self::hasUnresolvedPlaceholders($text)) {
            $problems[] = 'Message still has placeholders (e.g. [Your Name], {{firstName}}) — ask the user for the real value or use their profile name.';
        }

        return $problems;
    }

    public static function assertSendable(string $text): void
    {
        $problems = self::problems($text);
        if ($problems === []) {
            return;
        }

        throw new \InvalidArgumentException(implode(' ', $problems));
    }

    /**
     * Soft check for campaign / auto-send paths (skip or fail the step).
     * Empty string = ok to send on any channel (email, LinkedIn, WA, IG, TG, X).
     */
    public static function blockReason(string $text): ?string
    {
        $problems = self::problems($text);

        return $problems === [] ? null : implode(' ', $problems);
    }

    public static function looksLikeInternalPlan(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }

        // Imperative briefing to Alex / the operator, not the prospect.
        $openers = [
            '/^Reply with\b/i',
            '/^Thank them\b/i',
            '/^Ask them\b/i',
            '/^Send them\b/i',
            '/^Tell them\b/i',
            '/^Invite them\b/i',
            '/^Explain that\b/i',
            '/^Generate (the|a|an)\b/i',
            '/^Draft (a|an|the)\b/i',
            '/^Write (a|an|the)\b/i',
            '/^Plan (things|out|the)\b/i',
            '/^Here\'s (the|what) (you|I|we) should\b/i',
            '/^Best next step:/i',
            '/^Recommended action:/i',
        ];

        foreach ($openers as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        // Instructional stack: "Reply with… Thank them… Ask them…"
        $instructionHits = 0;
        foreach (['thank them', 'ask them', 'explain that', 'invite them', 'tell them', 'send them'] as $phrase) {
            if (stripos($text, $phrase) !== false) {
                $instructionHits++;
            }
        }

        if ($instructionHits >= 2) {
            return true;
        }

        // "Reply with X: facts… Thank them…"
        if (preg_match('/^Reply with[^:\n]{0,80}:/i', $text) && preg_match('/\b(thank them|ask them|explain that)\b/i', $text)) {
            return true;
        }

        return false;
    }

    public static function hasUnresolvedPlaceholders(string $text): bool
    {
        if (preg_match('/\{\{[a-zA-Z0-9_.]+\}\}/', $text)) {
            return true;
        }

        if (preg_match('/\[(?:Your\s+)?Name\]/i', $text)) {
            return true;
        }

        if (preg_match('/\[(?:Company|Email|Phone|Title|Position|Link|URL|Date|Time)\]/i', $text)) {
            return true;
        }

        if (preg_match('/\b(?:TODO|TBD|FIXME)\b/', $text)) {
            return true;
        }

        return false;
    }
}

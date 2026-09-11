<?php

namespace App\V2\Ai\Support;

use App\Models\User;
use App\Models\V2OutreachLead;

/**
 * Blocks internal planning text and unresolved placeholders from being sent to prospects.
 */
final class RecipientFacingCopyGuard
{
    /**
     * Fill sender/lead placeholders from profile + lead before send checks.
     * Prefer an explicit name the user told Soci, then preferred sender, then profile.
     *
     * @param  array{
     *     user?: ?User,
     *     organization_id?: ?int,
     *     lead?: ?V2OutreachLead,
     *     first_name?: ?string,
     *     campaign?: ?\App\Models\V2OutreachCampaign,
     *     sender_name?: ?string
     * }  $context
     */
    public static function prepareOutbound(string $text, array $context = []): string
    {
        $text = (string) $text;
        if (trim($text) === '') {
            return $text;
        }

        $user = $context['user'] ?? null;
        $orgId = isset($context['organization_id']) ? (int) $context['organization_id'] : null;
        $lead = $context['lead'] ?? null;
        $campaign = $context['campaign'] ?? null;
        $firstName = trim((string) ($context['first_name'] ?? ''));

        if ($firstName === '' && $lead instanceof V2OutreachLead) {
            $full = trim((string) ($lead->full_name ?? ''));
            if ($full !== '') {
                $firstName = trim((string) (explode(' ', $full)[0] ?? ''));
            }
        }

        $sender = '';
        if ($user instanceof User) {
            $sender = SenderIdentity::displayName(
                $user,
                $orgId,
                $campaign instanceof \App\Models\V2OutreachCampaign ? $campaign : null,
                isset($context['sender_name']) ? (string) $context['sender_name'] : null,
            );
        } elseif (trim((string) ($context['sender_name'] ?? '')) !== '') {
            $sender = trim((string) $context['sender_name']);
        }

        if ($sender !== '') {
            $text = preg_replace('/\[(?:Your\s+)?Name\]/iu', $sender, $text) ?? $text;
            $text = str_ireplace(
                ['{{senderName}}', '{{sender_name}}', '{{yourName}}', '{{your_name}}', '{{Your Name}}'],
                $sender,
                $text
            );
        } else {
            // No profile name — strip signature placeholder instead of hard-failing the send.
            $text = preg_replace('/[ \t]*\[(?:Your\s+)?Name\][ \t]*/iu', '', $text) ?? $text;
        }

        if ($firstName !== '') {
            $text = str_replace(['{{firstName}}', '{{first_name}}'], $firstName, $text);
        } else {
            // Soft greeting fallback so one-shots are not blocked on missing lead name.
            $text = preg_replace('/\{\{\s*first[_]?name\s*\}\}/iu', 'there', $text) ?? $text;
        }

        if ($lead instanceof V2OutreachLead) {
            $meta = is_array($lead->meta) ? $lead->meta : [];
            $company = trim((string) ($meta['company'] ?? $meta['company_name'] ?? ''));
            $position = trim((string) ($meta['position'] ?? $meta['title'] ?? $meta['headline'] ?? $lead->headline ?? ''));
            $parts = preg_split('/\s+/u', trim((string) ($lead->full_name ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $lastName = count($parts) > 1 ? (string) end($parts) : '';

            if ($company !== '') {
                $text = str_replace(['{{company}}', '{{Company}}'], $company, $text);
            }
            if ($position !== '') {
                $text = str_replace(['{{position}}', '{{title}}', '{{Title}}'], $position, $text);
            }
            if ($lastName !== '') {
                $text = str_replace(['{{lastName}}', '{{last_name}}'], $lastName, $text);
            }
        }

        return $text;
    }

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
            $problems[] = 'Message still has placeholders that could not be filled from your profile or the lead.';
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

        // Imperative briefing to Soci / the operator, not the prospect.
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

<?php

namespace App\V2\Ai\Support;

use Illuminate\Support\Str;

/**
 * Short campaign titles for the Outreach list — never dump the full goal brief.
 */
class CampaignDisplayName
{
    private const MAX_LEN = 56;

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPlan(string $goal, array $payload = []): string
    {
        $explicit = trim((string) ($payload['campaign_name'] ?? ''));
        if ($explicit !== '') {
            return self::prefix(self::clamp(self::clean($explicit)));
        }

        $core = self::summarizeGoal($goal);
        $hint = self::channelHint(
            (string) ($payload['preferred_channels'] ?? $payload['channels'] ?? '')
        );

        $title = $hint !== '' && ! str_contains(Str::lower($core), Str::lower($hint))
            ? $core.' · '.$hint
            : $core;

        return self::prefix(self::clamp($title));
    }

    private static function summarizeGoal(string $goal): string
    {
        $t = self::clean($goal);
        if ($t === '') {
            return 'Outreach campaign';
        }

        // Drop secondary asks so titles stay scannable.
        foreach ([' and ask ', ' and whether ', ' and confirm ', ' and see ', '. ', '; ', ' — ', ' - '] as $cut) {
            $pos = stripos($t, $cut);
            if ($pos !== false && $pos >= 10) {
                $t = trim(substr($t, 0, $pos));
                break;
            }
        }

        if ($keyword = self::keywordTitle($t)) {
            return $keyword;
        }

        // "Invite … to X" → keep the destination/theme.
        if (preg_match('/\b(?:invite|email|message|reach out(?: to)?)\b.+?\b(?:to|for)\b\s+(.+)$/iu', $t, $m)) {
            $rest = self::clean((string) $m[1]);
            if (mb_strlen($rest) >= 8) {
                return self::titleCase(Str::limit($rest, 42, ''));
            }
        }

        $words = preg_split('/\s+/u', $t) ?: [];
        if (count($words) > 8) {
            $t = implode(' ', array_slice($words, 0, 8));
        }

        return self::titleCase(rtrim($t, ' .,;:'));
    }

    private static function keywordTitle(string $t): ?string
    {
        $invite = (bool) preg_match('/\b(invite|facilitat\w*|speak(?:er)?|guest|host)\b/iu', $t);

        if (preg_match('/\b(annual event|product launch|demo day|webinar|online .+? show)\b/iu', $t, $m)) {
            $event = self::titleCase((string) $m[1]);

            return $invite ? $event.' invite' : $event;
        }

        if (preg_match('/\b(facilitat\w*|speaker|guest)\b/iu', $t) && preg_match('/\bevent\b/iu', $t)) {
            return 'Event facilitator invite';
        }

        if (preg_match('/\bbook(?:ing)? (?:a )?meeting\b/iu', $t)) {
            return 'Meeting booking';
        }

        if (preg_match('/\b(follow[-\s]?up|nurture)\b/iu', $t)) {
            return 'Follow-up outreach';
        }

        return null;
    }

    private static function channelHint(string $channels): string
    {
        $c = Str::lower($channels);
        if ($c === '') {
            return '';
        }

        $labels = [];
        foreach (['linkedin' => 'LinkedIn', 'email' => 'Email', 'whatsapp' => 'WhatsApp', 'instagram' => 'Instagram', 'telegram' => 'Telegram', 'twitter' => 'X'] as $needle => $label) {
            if (str_contains($c, $needle) || ($needle === 'twitter' && (str_contains($c, ' x ') || $c === 'x'))) {
                $labels[] = $label;
            }
        }

        if ($labels === []) {
            return '';
        }

        // One channel → short suffix; multi → skip (title already crowded).
        return count($labels) === 1 ? $labels[0] : '';
    }

    private static function clean(string $value): string
    {
        $t = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        $t = preg_replace('/\b[\w.+-]+@[\w.-]+\.\w{2,}\b/u', '', $t) ?? $t;
        $t = preg_replace('#https?://\S+#iu', '', $t) ?? $t;
        $t = preg_replace('/\s{2,}/u', ' ', $t) ?? $t;
        $t = preg_replace('/\b(and|or|,)\s*(and|or|,)\b/iu', ' ', $t) ?? $t;

        return trim($t, " \t\n\r\0\x0B,;.");
    }

    private static function titleCase(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return $value;
        }

        // Keep intentional casing for short phrases; only fix leading lowercase.
        return mb_strtoupper(mb_substr($value, 0, 1)).mb_substr($value, 1);
    }

    private static function clamp(string $value): string
    {
        $value = trim($value);
        if (mb_strlen($value) <= self::MAX_LEN) {
            return $value;
        }

        return rtrim(Str::limit($value, self::MAX_LEN, ''), ' .,;:').'…';
    }

    private static function prefix(string $title): string
    {
        $title = preg_replace('/^AI:\s*/iu', '', $title) ?? $title;
        $title = trim($title);

        return $title === '' ? 'AI: Outreach campaign' : 'AI: '.$title;
    }
}

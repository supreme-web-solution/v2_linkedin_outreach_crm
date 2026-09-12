<?php

namespace App\V2\Ai\Services;

use Illuminate\Support\Str;

/**
 * Lightweight intent guard so status questions don't trigger prospect discovery.
 */
class UserTurnIntentService
{
    public function isInformational(string $message): bool
    {
        $lower = Str::lower(trim($message));
        if ($lower === '') {
            return false;
        }

        if ($this->isProspectDiscoveryRequest($lower)) {
            return false;
        }

        $patterns = [
            '/\b(what|whats|what\'s)\s+(do\s+we\s+have|have\s+we\s+got|is\s+going\s+on|happened|s\s+happening)\b/',
            '/\bwhat\s+do\s+we\s+have\s+today\b/',
            '/\b(today\'s\s+update|daily\s+(summary|update|brief)|catch\s+me\s+up)\b/',
            '/\b(give\s+me\s+(an\s+)?update|status\s+update|pipeline\s+update)\b/',
            '/\b(how\s+(are\s+)?things|how\'s\s+it\s+going|how\s+is\s+it\s+going)\b/',
            '/\b(show\s+me\s+(what\s+we\s+have|today|the\s+pipeline|our\s+numbers))\b/',
            '/\b(what\'s\s+running|what\s+is\s+running|what\'s\s+active)\b/',
            '/^(brief|status|update|summary|today)$/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $lower)) {
                return true;
            }
        }

        if (preg_match('/\b(get|find|discover|fetch)\b.{0,80}\b(launch|start|run|activate)\b.{0,20}\b(now|immediately|right away)\b/i', $lower)) {
            return true;
        }

        if (str_contains($lower, 'launch now')
            && preg_match('/\b(client|customer|prospect|lead|people|profile)s?\b/i', $lower)) {
            return true;
        }

        if (preg_match('/\b(find|discover|get|fetch)\b/i', $lower)
            && preg_match('/\b(activate|run|start|launch)\b/i', $lower)
            && preg_match('/\b(winner|winners|lead|leads|clients?|customers?)\b/i', $lower)) {
            return true;
        }

        if (preg_match('/\b(launch|start|run|activate)\b.{0,20}\b(now|immediately|right away)\b/i', $lower)
            && preg_match('/\b(client|customer|prospect|lead|people|profile)s?\b/i', $lower)) {
            return true;
        }

        if ($this->isProspectDiscoveryRequest($lower)
            && preg_match('/\b(launch|start|run|activate|send|message)\b/i', $lower)
            && preg_match('/\b(now|immediately|right away)\b/i', $lower)) {
            return true;
        }

        return false;
    }

    /**
     * User wants to find/save prospects — not necessarily start outreach.
     */
    public function isProspectDiscoveryRequest(string $message): bool
    {
        $lower = Str::lower(trim($message));

        if ((bool) preg_match('/\b(\d{1,3})\s*(client|customer|prospect|lead)s?\b/i', $lower)) {
            return true;
        }

        if (preg_match('/\b(save|store|collect)\b.{0,25}\b(prospects?|leads?|customers?|clients?|people|profiles?)\b/i', $lower)) {
            return true;
        }

        // Supports terse "get 30" discovery prompts when list/fresh context is explicit.
        if (preg_match('/\b(get|find|fetch|discover|search)\b.{0,20}\b\d{1,3}\b/i', $lower)
            && preg_match('/\b(list|lead|prospect|client|customer|reuse|fresh|new|more)\b/i', $lower)) {
            return true;
        }

        if (preg_match('/\b(search|find|discover|get|fetch)\b.{0,40}\b(linkedin|instagram|whatsapp|telegram|email)\b.{0,20}\b\d{1,3}\b/i', $lower)) {
            return true;
        }

        if (preg_match('/\b(find|search|discover|get|fetch)\b.{0,60}\b(founders?|cofounders?|ceos?|ctos?|cmos?|owners?|directors?|vps?|heads?)\b/i', $lower)) {
            return true;
        }

        return (bool) preg_match(
            '/\b(get|find|acquire|bring|fetch|search|discover)\b.{0,60}\b(client|customer|prospect|lead|people|profile)s?\b/i',
            $lower,
        );
    }

    /**
     * User explicitly asked to market, message, or run outreach — not find-only.
     */
    public function isOutreachCommand(string $message): bool
    {
        $lower = Str::lower(trim($message));

        $patterns = [
            '/\b(outreach|reach out|cold outreach)\b/',
            '/\b(start|launch|run|create|build|draft|activate)\b.{0,40}\b(campaigns?|outreach|sequences?)\b/',
            '/\b(campaigns?|outreach|sequences?)\b.{0,20}\b(for|to|on)\b/',
            '/\b(market to|message them|dm them|email them|contact them|send (messages|dms|emails))\b/',
            '/\b(start (messaging|outreach)|begin outreach|launch outreach)\b/',
            '/\breview\s*&\s*launch\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $lower)) {
                return true;
            }
        }

        return false;
    }

    public function isDiscoveryOnly(string $message): bool
    {
        return $this->isProspectDiscoveryRequest($message) && ! $this->isOutreachCommand($message);
    }

    /**
     * User named a single searchable discovery platform in their message.
     */
    public function explicitDiscoveryChannel(string $message): ?string
    {
        if ($this->wantsMultichannelDiscovery($message)) {
            return null;
        }

        $lower = Str::lower(trim($message));

        if ((bool) preg_match('/\b(linkedin|linked[\s-]?in)\b/i', $lower)) {
            return 'linkedin';
        }

        if ((bool) preg_match('/\b(instagram|instgram|insta\b|ig\b)\b/i', $lower)) {
            return 'instagram';
        }

        return null;
    }

    public function wantsMultichannelDiscovery(string $message): bool
    {
        $lower = Str::lower(trim($message));

        return (bool) preg_match(
            '/\b(all channels?|every channel|both channels?|multichannel|multi[- ]channel)\b/i',
            $lower,
        );
    }

    /**
     * @deprecated Use explicitDiscoveryChannel() — no platform is assumed for vague find/save asks.
     */
    public function wantsInstagramDiscovery(string $message): bool
    {
        return $this->explicitDiscoveryChannel($message) === 'instagram';
    }

    /**
     * @deprecated Use explicitDiscoveryChannel() — LinkedIn is not inferred from vague discovery phrasing.
     */
    public function prefersLinkedInOnlyDiscovery(string $message): bool
    {
        return $this->explicitDiscoveryChannel($message) === 'linkedin';
    }

    /**
     * User wants net-new profiles — not a repeat of a list Soci just pulled.
     */
    public function wantsFreshProspectPull(string $message): bool
    {
        $lower = Str::lower(trim($message));
        if ($lower === '') {
            return false;
        }

        $freshSignals = [
            '/\b(fresh|brand new|from scratch|start over|new batch)\b/',
            '/\b(don\'?t|do not|dont)\s+(reuse|recycle|use the same|repeat)\b/',
            '/\bnot\s+(the\s+)?same\b/',
            '/\b(different|another batch|other people|new people|new profiles|new prospects)\b/',
            '/\bpull\s+(a\s+)?fresh\b/',
            '/\b(no\s+reuse|without reusing|don\'t reuse)\b/',
        ];

        foreach ($freshSignals as $pattern) {
            if (preg_match($pattern, $lower)) {
                return true;
            }
        }

        if (preg_match('/\b(more|another|additional|extra)\b/i', $lower)
            && preg_match('/\b(prospect|lead|customer|client|people|profile)s?\b/i', $lower)) {
            return true;
        }

        if (preg_match('/\b(\d{1,3})\s+more\b/i', $lower)) {
            return true;
        }

        if (preg_match('/\bmore\s+(\d{1,3})\b/i', $lower)) {
            return true;
        }

        return false;
    }

    /**
     * User wants a campaign staged/reviewed but explicitly not launched or sent yet.
     */
    public function wantsCampaignSetupOnly(string $message): bool
    {
        $lower = Str::lower(trim($message));
        if ($lower === '') {
            return false;
        }

        $patterns = [
            '/\b(don\'?t|do not|dont)\s+(send|launch|start|activate|run|message|dm|email)\b/',
            '/\b(not|never)\s+(send|launch|start|activate|run)\b/',
            '/\b(no\s+send|without\s+sending|do\s+not\s+send)\b/',
            '/\bno\s+(launch|send|start|activate|run)\b/',
            '/\b(setup|set\s+up|stage|prepare|draft|create)\b.{0,40}\b(but|and)\s+(don\'?t|do not|dont|not)\s+(send|launch|start|activate|run)\b/',
            '/\b(create|build|draft|stage|prepare)\b.{0,30}\b(campaign|outreach|sequence)\b.{0,40}\b(but|and)\s+(don\'?t|do not|dont|not)\s+(send|launch|start|activate|run)\b/',
            '/\bjust\s+(setup|set\s+up|set\s+it\s+up|stage|prepare|draft|create)\b/',
            '/\b(setup|set\s+up|stage|prepare)\s+only\b/',
            '/\bready\s+for\s+review\b/',
            '/\b(for\s+review\s+only|review\s+only)\b/',
            '/\breview\s+panel\s+only\b/',
            '/\breview\s*&\s*launch\s+later\b/',
            '/\b(not\s+now|launch\s+later|send\s+later|hold|wait\s+for\s+my\s+go)\b/',
            '/\b(plan|draft|stage|prepare)\b.{0,20}\b(campaign|outreach|sequence)\b.{0,20}\b(first)\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $lower)) {
                return true;
            }
        }

        return false;
    }

    /**
     * User wants Soci to draft or send a Unified Inbox reply (not a new campaign).
     */
    public function isInboxReplyRequest(string $message): bool
    {
        $lower = Str::lower(trim($message));
        if ($lower === '') {
            return false;
        }

        if ($this->isOutreachCommand($lower) || $this->isProspectDiscoveryRequest($lower)) {
            return false;
        }

        $patterns = [
            '/\b(generate|write|draft|create|compose|prepare)\b.{0,50}\b(reply|response|email|message)\b/i',
            '/\b(send|reply|respond)\b.{0,40}\b(to|for|back to)\b/i',
            '/\bemail response for\b/i',
            '/\breply to\b.{0,60}\b(email|message|inbox|thread)\b/i',
            '/\bfor that email we (received|got)\b/i',
            '/\bdraft.{0,30}\b(for|to)\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $lower)) {
                return true;
            }
        }

        return false;
    }

    public function wantsDraftOnly(string $message): bool
    {
        $lower = Str::lower(trim($message));

        return (bool) preg_match(
            '/\b(don\'?t|do not|dont|not)\s+(send|launch|deliver|ship)\b|\b(draft only|for review|don\'t send|do not send)\b/i',
            $lower,
        );
    }

    /**
     * @return array{email: string|null, name: string|null}
     */
    public function extractInboxReplyTarget(string $message): array
    {
        $email = null;
        $name = null;

        if (preg_match('/[\w.+-]+@[\w.-]+\.\w+/', $message, $match)) {
            $email = Str::lower(trim($match[0]));
        }

        if (preg_match('/\bfor\s+([a-z0-9][\w.-]{1,60})\b/i', $message, $match)) {
            $candidate = trim($match[1]);
            if (! str_contains($candidate, '@') && ! in_array(Str::lower($candidate), ['that', 'the', 'this', 'them', 'him', 'her'], true)) {
                $name = $candidate;
            }
        }

        if ($name === null && preg_match('/\breply to\s+([a-z0-9][\w\s.-]{1,40}?)(?:\s+(?:on|via|in|for|about)\b|$)/i', $message, $match)) {
            $name = trim($match[1]);
        }

        return ['email' => $email, 'name' => $name];
    }

    /** @deprecated Use isProspectDiscoveryRequest() */
    public function isAcquisitionCommand(string $message): bool
    {
        return $this->isProspectDiscoveryRequest($message);
    }
}

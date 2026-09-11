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
            '/\b(start|launch|run|create|build|draft|activate)\b.{0,40}\b(campaign|outreach|sequence)\b/',
            '/\b(campaign|outreach|sequence)\b.{0,20}\b(for|to|on)\b/',
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
            '/\b(setup|set\s+up|stage|prepare|draft|create)\b.{0,40}\b(but|and)\s+(don\'?t|do not|dont|not)\s+(send|launch|start|activate|run)\b/',
            '/\b(create|build|draft|stage|prepare)\b.{0,30}\b(campaign|outreach|sequence)\b.{0,40}\b(but|and)\s+(don\'?t|do not|dont|not)\s+(send|launch|start|activate|run)\b/',
            '/\bjust\s+(setup|set\s+up|stage|prepare|draft|create)\b/',
            '/\b(setup|set\s+up|stage|prepare)\s+only\b/',
            '/\bready\s+for\s+review\b/',
            '/\breview\s*&\s*launch\s+later\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $lower)) {
                return true;
            }
        }

        return false;
    }

    /** @deprecated Use isProspectDiscoveryRequest() */
    public function isAcquisitionCommand(string $message): bool
    {
        return $this->isProspectDiscoveryRequest($message);
    }
}

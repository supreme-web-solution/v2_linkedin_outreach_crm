<?php

namespace App\V2\Ai\Services;

use Illuminate\Support\Str;

/**
 * Structural parsers + emergency keyword fallback when SemanticTurnPlanService is unavailable.
 *
 * Prefer SemanticTurnPlan fields for meaning (cold_one_shot, inbox_reply, prepare_only, etc.).
 * Keep using this class for: email/URL/phone/handle extraction, control-command parsing,
 * observability labels, and IntentGoalResolver fallback when the LLM planner fails.
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
    /**
     * Keyword fallback when the semantic planner is unavailable.
     * Prefer thread-aware LLM interpretation for short "go ahead / just N" turns.
     */
    public function isProceedWithTargetCount(string $message): bool
    {
        $lower = Str::lower(trim($message));
        if ($lower === '' || ! preg_match('/\b(\d{1,3})\b/', $lower, $match)) {
            return false;
        }

        $count = (int) $match[1];
        if ($count < 1 || $count > 100) {
            return false;
        }

        if (preg_match('/\b(campaign|delete|inbox|reply)\b/', $lower)) {
            return false;
        }

        if (preg_match('/\b(just|only|about|around)\s+'.$count.'\b/', $lower)) {
            return true;
        }

        if (preg_match('/\b'.$count.'\s+(is|ia|are|s)\s+(okay|ok|fine|enough|good)\b/', $lower)) {
            return true;
        }

        return (bool) preg_match('/\b(go ahead|proceed)\b/', $lower);
    }

    public function isProspectDiscoveryRequest(string $message): bool
    {
        $lower = Str::lower(trim($message));

        if ($this->isProceedWithTargetCount($lower)) {
            return true;
        }

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
            '/\b(don\'?t|do not|dont)\s+(send|launch|start|activate|run|message|dm|email|sen)\b/',
            '/\b(but|and)\s+(don\'?t|do not|dont|not)\s+(sen|send|launch)\b/',
            '/\bdont\s+sen\b/',
            '/\b(not|never)\s+(send|launch|start|activate|run)\b/',
            '/\b(no\s+send|without\s+sending|do\s+not\s+send)\b/',
            '/\bno\s+(launch|send|start|activate|run)\b/',
            '/\b(setup|set\s+up|stage|prepare|draft|create)\b.{0,40}\b(but|and)\s+(don\'?t|do not|dont|not)\s+(send|launch|start|activate|run)\b/',
            '/\b(create|build|draft|stage|prepare)\b.{0,30}\b(campaign|campagin|outreach|sequence)\b.{0,40}\b(but|and)\s+(don\'?t|do not|dont|not)\s+(send|launch|start|activate|run)\b/',
            '/\bset\s+up\s+a\s+camp[a-z]*\b.{0,20}\b(but|and)\s+(don\'?t|do not|dont|not)\s+(sen|send|launch)\b/',
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

        // Short "build this/it" in a thread means stage the plan for Review & Launch — not send.
        if (! preg_match('/\b(send|launch|start|activate|run|dm|message)\b/', $lower)
            && preg_match('/^(please\s+)?(build|rebuild|recreate|make)\s+(this|it|that|the\s+plan)\s*[.!]?\s*$/', $lower)
        ) {
            return true;
        }

        return false;
    }

    /**
     * User wants to launch, send, or stage the outreach campaign (not inbox reply).
     */
    public function isCampaignActionRequest(string $message): bool
    {
        $lower = Str::lower(trim($message));
        if ($lower === '') {
            return false;
        }

        if ($this->isInboxReplyRequest($message)) {
            return false;
        }

        $patterns = [
            '/\b(send|launch|start|run|activate)\b.{0,25}\b(the\s+)?campaign\b/',
            '/\b(create|build|stage|set\s+up)\b.{0,40}\b(campaign|outreach)\b.{0,40}\b(to|for)\b/',
            '/\b(send|launch|start)\s+it\b/',
            '/\breview\s*&\s*launch\b/',
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

        // Cold email/DM/URL/phone is outbound — not an inbox reply to an existing thread.
        if ($this->isColdOutboundRequest($message)) {
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

    /**
     * Keyword fallback when the semantic planner is unavailable.
     * Prefer SemanticTurnPlan cold_one_shot / recipient_correction in production.
     *
     * User is correcting a wrong recipient on a cold outbound they just staged/sent.
     * Channel-agnostic: email typo, wrong handle, wrong phone, etc.
     */
    public function isOutboundRecipientCorrection(string $message): bool
    {
        $lower = Str::lower(trim($message));
        if ($lower === '') {
            return false;
        }

        $hasIdentity = ($this->extractColdOutboundIdentity($message)['channel'] ?? null) !== null;
        if (! $hasIdentity) {
            return false;
        }

        // Soft structural cue only — real intent comes from the semantic planner.
        return (bool) preg_match(
            '/\b(incorrect|wrong|typo|misspelled|mistake|sorry|oops|meant|instead|not that|fix)\b/i',
            $lower,
        ) && (bool) preg_match(
            '/\b(email|mail|send|dm|message|whatsapp|telegram|instagram|linkedin|twitter|\bx\b|to this|to him|to her)\b/i',
            $lower,
        );
    }

    /**
     * Keyword fallback when the semantic planner is unavailable.
     * Prefer SemanticTurnPlan cold_one_shot in production.
     *
     * Cold one-shot to a named contact (email, LinkedIn URL, IG, WhatsApp, Telegram, X)
     * without requiring an existing Unified Inbox thread.
     */
    public function isColdOutboundRequest(string $message): bool
    {
        $lower = Str::lower(trim($message));
        if ($lower === '') {
            return false;
        }

        if ($this->looksLikeExistingInboxContext($lower)) {
            return false;
        }

        $identity = $this->extractColdOutboundIdentity($message);
        if (($identity['channel'] ?? null) === null) {
            return false;
        }

        if ($this->isOutboundRecipientCorrection($message)) {
            return true;
        }

        $action = (bool) preg_match(
            '/\b(email|e-?mail|mail|dm|message|send|reach\s+out|contact|whatsapp|wa|telegram|ig|instagram|tweet|twitter|linkedin|write|draft|compose|plan(?:ned)?\s+reply|intro|check|gather|tailor)\b/i',
            $lower,
        );

        return $action;
    }

    /**
     * @return array{
     *     channel: ?string,
     *     email: ?string,
     *     phone: ?string,
     *     linkedin_url: ?string,
     *     instagram_handle: ?string,
     *     telegram_handle: ?string,
     *     twitter_handle: ?string,
     *     research_url: ?string,
     *     display_name: ?string
     * }
     */
    public function extractColdOutboundIdentity(string $message): array
    {
        $empty = [
            'channel' => null,
            'email' => null,
            'phone' => null,
            'linkedin_url' => null,
            'instagram_handle' => null,
            'telegram_handle' => null,
            'twitter_handle' => null,
            'research_url' => null,
            'display_name' => null,
        ];

        $text = trim($message);
        if ($text === '') {
            return $empty;
        }

        $lower = Str::lower($text);
        $researchUrl = null;
        if (preg_match_all('#https?://[^\s<>"\']+#i', $text, $urlMatches)) {
            foreach ($urlMatches[0] as $url) {
                $clean = rtrim($url, '.,);]');
                if (preg_match('#linkedin\.com/in/#i', $clean)
                    || preg_match('#instagram\.com/#i', $clean)
                    || preg_match('#(?:t\.me|telegram\.me)/#i', $clean)
                    || preg_match('#(?:twitter\.com|x\.com)/#i', $clean)
                ) {
                    continue;
                }
                $researchUrl = $clean;
                break;
            }
        }

        $email = null;
        if (preg_match('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $text, $m)) {
            $email = Str::lower($m[0]);
        }

        $linkedinUrl = null;
        if (preg_match('#https?://(?:www\.)?linkedin\.com/in/[\w%-]+/?#i', $text, $m)) {
            $linkedinUrl = rtrim($m[0], '.,);]');
        }

        $instagramHandle = null;
        if (preg_match('#instagram\.com/([a-z0-9._]{2,30})/?#i', $text, $m)) {
            $instagramHandle = Str::lower($m[1]);
        } elseif (preg_match('/\b(?:instagram|ig)\b.{0,40}@([a-z0-9._]{2,30})\b/i', $text, $m)) {
            $instagramHandle = Str::lower($m[1]);
        }

        $telegramHandle = null;
        if (preg_match('#(?:t\.me|telegram\.me)/([a-z0-9_]{5,32})#i', $text, $m)) {
            $telegramHandle = Str::lower($m[1]);
        } elseif (preg_match('/\btelegram\b.{0,40}@([a-z0-9_]{5,32})\b/i', $text, $m)) {
            $telegramHandle = Str::lower($m[1]);
        }

        $twitterHandle = null;
        if (preg_match('#(?:twitter\.com|x\.com)/([a-z0-9_]{1,30})#i', $text, $m)) {
            $twitterHandle = Str::lower($m[1]);
        } elseif (preg_match('/\b(?:twitter|x)\b.{0,40}@([a-z0-9_]{1,30})\b/i', $text, $m)) {
            $twitterHandle = Str::lower($m[1]);
        }

        $phone = null;
        if (preg_match('/(?:\+|whatsapp|\bwa\b|call|text|sms)\s*[:#]?\s*(\+?\d[\d\s().-]{7,}\d)/i', $text, $m)
            || (preg_match('/\b(?:whatsapp|wa)\b/i', $lower) && preg_match('/\+?\d[\d\s().-]{7,}\d/', $text, $phoneMatch))
        ) {
            $raw = $m[1] ?? ($phoneMatch[0] ?? null);
            $phone = $raw !== null ? (preg_replace('/\s+/', '', $raw) ?: null) : null;
        }

        // Bare @handle with explicit channel words (domain-agnostic — any niche).
        if ($instagramHandle === null
            && preg_match('/\b(?:instagram|ig)\b/i', $lower)
            && preg_match('/(?:^|\s)@([a-z0-9._]{2,30})\b/i', $text, $m)
        ) {
            $instagramHandle = Str::lower($m[1]);
        }
        if ($telegramHandle === null
            && preg_match('/\btelegram\b/i', $lower)
            && preg_match('/(?:^|\s)@([a-z0-9_]{5,32})\b/i', $text, $m)
        ) {
            $telegramHandle = Str::lower($m[1]);
        }

        $channel = null;
        if ($linkedinUrl !== null && preg_match('/\b(linkedin|dm|message|send|reach\s+out)\b/i', $lower)) {
            $channel = 'linkedin';
        } elseif ($linkedinUrl !== null && ! preg_match('/\b(email|whatsapp|telegram|instagram|twitter|\bx\b)\b/i', $lower)) {
            $channel = 'linkedin';
        } elseif ($email !== null && (
            preg_match('/\b(email|e-mail|mail)\b/i', $lower)
            || preg_match('/\b(send|write|draft|compose|plan(?:ned)?\s+reply)\b/i', $lower)
        )) {
            $channel = 'email';
        } elseif ($instagramHandle !== null) {
            $channel = 'instagram';
        } elseif ($telegramHandle !== null || (preg_match('/\btelegram\b/i', $lower) && $phone !== null)) {
            $channel = 'telegram';
        } elseif ($twitterHandle !== null) {
            $channel = 'twitter';
        } elseif ($phone !== null && preg_match('/\b(?:whatsapp|wa)\b/i', $lower)) {
            $channel = 'whatsapp';
        } elseif ($email !== null) {
            $channel = 'email';
        } elseif ($phone !== null) {
            $channel = 'whatsapp';
        } elseif ($linkedinUrl !== null) {
            $channel = 'linkedin';
        } elseif ($instagramHandle !== null && preg_match('/\b(?:instagram|ig|dm|message)\b/i', $lower)) {
            $channel = 'instagram';
        }

        return [
            'channel' => $channel,
            'email' => $email,
            'phone' => $phone,
            'linkedin_url' => $linkedinUrl,
            'instagram_handle' => $instagramHandle,
            'telegram_handle' => $telegramHandle,
            'twitter_handle' => $twitterHandle,
            'research_url' => $researchUrl,
            'display_name' => $email ?? $linkedinUrl ?? $instagramHandle ?? $telegramHandle ?? $twitterHandle ?? $phone,
        ];
    }

    private function looksLikeExistingInboxContext(string $lower): bool
    {
        return (bool) preg_match(
            '/\b(inbox|attention queue|that email we (received|got)|existing thread|open thread|from (the )?inbox)\b/',
            $lower,
        );
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
     * User wants to resend a staged inbox reply (often after a failed LAUNCH or reconnect).
     */
    public function isInboxReplyRetryRequest(string $message): bool
    {
        $lower = Str::lower(trim($message));
        if ($lower === '') {
            return false;
        }

        if ($this->isOutreachCommand($lower) || $this->isProspectDiscoveryRequest($lower)) {
            return false;
        }

        $patterns = [
            '/\b(try|send|do)\s+(it\s+)?again\b/',
            '/\b(send|resend|retry)\s+(the\s+)?(reply|message|it)\b/',
            '/\b(send|resend)\s+again\b/',
            '/\b(it\s+)?has\s+been\s+enabled\b.{0,40}\b(send|retry)\b/',
            '/\b(messaging|send(?:ing)?)\s+(is\s+)?enabled\b/',
            '/\bplease\s+(try|send)\s+again\b/',
            '/\bgo\s+ahead\s+and\s+send\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $lower)) {
                return true;
            }
        }

        return false;
    }

    /**
     * User picked one prospect from Soci's "Which message should I resend?" list.
     */
    public function isInboxResendSelection(string $message): bool
    {
        $trimmed = trim($message);
        if ($trimmed === '') {
            return false;
        }

        if (preg_match('/\bconversation\s+#?\d+\b/i', $trimmed)) {
            return true;
        }

        return (bool) preg_match('/^[A-Za-z0-9].+?\s*[—–-]\s*.+/u', $trimmed);
    }

    /**
     * @return array{name: string|null, conversation_id: int|null}
     */
    public function extractInboxResendSelection(string $message): array
    {
        $trimmed = trim($message);
        $conversationId = null;
        $name = null;

        if (preg_match('/\bconversation\s+#?(\d+)\b/i', $trimmed, $match)) {
            $conversationId = (int) $match[1];
        }

        if (preg_match('/^(.+?)\s*[—–-]\s*/u', $trimmed, $match)) {
            $name = trim($match[1]);
        } elseif (preg_match('/\bfor\s+([A-Za-z][\w\s.-]{1,60})\b/u', $trimmed, $match)) {
            $name = trim($match[1]);
        }

        return [
            'name' => $name !== '' ? $name : null,
            'conversation_id' => $conversationId > 0 ? $conversationId : null,
        ];
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

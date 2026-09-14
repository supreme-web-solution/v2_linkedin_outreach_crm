<?php

namespace App\V2\Ai\Services;

use App\Ai\Agents\OutboundCopyAgent;
use App\Models\User;
use App\V2\Ai\Support\EmailOutboundFormat;
use App\V2\Services\LaravelAiWebResearchService;
use App\V2\Services\OpenAIContentService;
use App\V2\Services\WebScrapeChainService;
use Illuminate\Support\Str;

/**
 * Channel-aware outbound copy: research + one Laravel AI OutboundCopyAgent for all channels.
 * Email is treated as a real email (substance + soft pitch); DMs stay short.
 * OpenAI HTTP is emergency fallback only.
 */
class OutboundMessageComposerService
{
    public function __construct(
        private readonly OpenAIContentService $openai,
        private readonly LaravelAiWebResearchService $webResearch,
        private readonly WebScrapeChainService $scraper,
        private readonly WorkspaceContextService $workspace,
        private readonly AiEmployeeSettingsService $settingsService,
        private readonly AiProviderChain $providers,
        private readonly OutboundCopyPrefsService $copyPrefs,
    ) {}

    /**
     * Gather readable research for a URL (scrape, then Laravel AI WebFetch/search if thin).
     */
    public function researchUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || $this->scraper->shouldSkipUrl($url)) {
            return '';
        }

        $chunks = [];

        try {
            $scraped = $this->scraper->scrape($url);
            if ($scraped['ok'] ?? false) {
                $title = trim((string) ($scraped['title'] ?? ''));
                $content = trim((string) ($scraped['content'] ?? $scraped['excerpt'] ?? ''));
                if ($title !== '') {
                    $chunks[] = 'Title: '.$title;
                }
                $chunks[] = 'URL: '.($scraped['url'] ?? $url);
                if ($content !== '') {
                    $chunks[] = Str::limit($content, 4500);
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }

        $notes = trim(implode("\n", $chunks));
        if ($this->researchIsSubstantial($notes)) {
            return $notes;
        }

        if ($this->webResearch->isEnabled()) {
            try {
                $fetched = $this->webResearch->supportsWebFetch()
                    ? $this->webResearch->fetchUrl($url)
                    : null;
                if (is_array($fetched) && ($fetched['ok'] ?? false) && trim((string) ($fetched['content'] ?? '')) !== '') {
                    return trim(implode("\n", array_filter([
                        ! empty($fetched['title']) ? 'Title: '.$fetched['title'] : null,
                        'URL: '.$url,
                        'AI research notes:',
                        Str::limit(trim((string) $fetched['content']), 4000),
                    ])));
                }

                $host = parse_url($url, PHP_URL_HOST) ?: $url;
                $searched = $this->webResearch->searchCompany((string) $host);
                if (is_array($searched) && ($searched['ok'] ?? false) && trim((string) ($searched['excerpt'] ?? '')) !== '') {
                    return trim(implode("\n", array_filter([
                        'URL: '.$url,
                        'AI research notes:',
                        Str::limit(trim((string) $searched['excerpt']), 4000),
                    ])));
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $notes;
    }

    public function researchIsSubstantial(string $notes): bool
    {
        $compact = trim(preg_replace('/\s+/', ' ', $notes) ?? '');

        return strlen($compact) >= 120;
    }

    /**
     * @param  array<string, mixed>  $identity
     * @param  list<array{role:string,content:string}>  $thread
     * @return array{body:string, subject:?string}
     */
    public function compose(
        User $user,
        int $organizationId,
        string $channel,
        string $ownerRequest,
        array $identity,
        string $researchNotes,
        ?string $offerOverride = null,
        ?string $priorDraft = null,
        array $thread = [],
        string $mode = 'outbound',
    ): array {
        $settings = $this->settingsService->for($user, $organizationId);
        $inputs = $this->workspace->icpInputsFromProfile($settings);
        $icp = $this->workspace->storedIcp($settings);
        $offer = trim((string) ($offerOverride ?: ($inputs['offer'] ?? '')));
        $icpAngle = trim((string) ($icp['outreach_angle'] ?? $icp['summary'] ?? $icp['who_we_sell_to'] ?? ''));
        $business = trim((string) ($this->workspace->agentContextBlock($user, $organizationId) ?? ''));
        $channel = strtolower(trim($channel));
        $recipient = $this->recipientLabel($identity);
        $prefsBlock = $this->copyPrefs->briefBlock($settings);

        $brief = [
            'mode' => $mode,
            'channel' => $channel,
            'recipient' => $recipient,
            'owner_request' => Str::limit(trim($ownerRequest), 800),
            'sender_offer' => $offer !== '' ? $offer : '(workspace profile only — do not invent products)',
            'icp_angle' => $icpAngle !== '' ? $icpAngle : null,
            'offer_override' => $offerOverride,
            'recipient_research' => $researchNotes !== '' ? Str::limit($researchNotes, $channel === 'email' ? 4500 : 1500) : null,
            'prior_draft_to_improve' => $priorDraft !== null && trim($priorDraft) !== '' ? Str::limit(trim($priorDraft), 1200) : null,
            'recent_thread' => $thread !== [] ? $thread : null,
            'workspace_brief' => $business !== '' ? Str::limit($business, 800) : null,
            'copy_prefs' => $prefsBlock,
        ];

        return $this->composeFromBrief($channel, $mode, $brief);
    }

    /**
     * Campaign / tool entry: evidence blob already assembled by the caller.
     *
     * @param  array<string, mixed>  $evidence
     * @return array{body:string, subject:?string}
     */
    public function composeFromEvidence(
        string $channel,
        string $mode,
        string $goal,
        array $evidence,
        ?string $businessContext = null,
        ?string $senderName = null,
        ?string $notes = null,
    ): array {
        $channel = strtolower(trim($channel));
        $researchParts = [];
        foreach ($evidence as $key => $value) {
            if ($value === null || trim((string) $value) === '') {
                continue;
            }
            $researchParts[] = Str::headline(str_replace('_', ' ', (string) $key)).': '.$value;
        }

        $brief = [
            'mode' => $mode,
            'channel' => $channel,
            'owner_request' => Str::limit(trim($goal), 800),
            'recipient_research' => $researchParts !== []
                ? Str::limit(implode("\n", $researchParts), $channel === 'email' ? 4500 : 1500)
                : null,
            'workspace_brief' => $businessContext !== null && trim($businessContext) !== ''
                ? Str::limit(trim($businessContext), 800)
                : null,
            'sender_name' => $senderName !== null && trim($senderName) !== '' ? trim($senderName) : null,
            'agent_notes' => $notes !== null && trim($notes) !== '' ? Str::limit(trim($notes), 600) : null,
            'sender_offer' => '(use workspace_brief / evidence only — do not invent products)',
        ];

        return $this->composeFromBrief($channel, $mode, $brief);
    }

    /**
     * Active inbox thread reply — same copy agent, inbox mode.
     *
     * @param  list<array{role?:string,body?:string,source?:string}>  $thread
     * @param  array<string, mixed>  $options
     * @return array{body:string, subject:?string}
     */
    public function composeInboxReply(
        string $channel,
        string $aiContext,
        array $thread,
        string $inboundBody,
        string $leadName,
        array $options = [],
    ): array {
        $channel = strtolower(trim($channel));
        $normalizedThread = [];
        foreach ($thread as $message) {
            $body = trim((string) ($message['body'] ?? ''));
            if ($body === '') {
                continue;
            }
            $role = ($message['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $source = (string) ($message['source'] ?? '');
            if ($role === 'assistant' && $source === 'outreach_campaign') {
                $body = "[Automated campaign step]\n{$body}";
            }
            $normalizedThread[] = ['role' => $role, 'content' => Str::limit($body, 800)];
        }

        $brief = [
            'mode' => 'inbox_reply',
            'channel' => $channel,
            'recipient' => $leadName !== '' ? $leadName : 'contact',
            'business_campaign_brief' => $aiContext !== '' ? Str::limit($aiContext, 1200) : null,
            'campaign_name' => $options['campaign_name'] ?? null,
            'lead_headline' => $options['lead_headline'] ?? null,
            'thread_summary' => isset($options['thread_summary']) ? Str::limit((string) $options['thread_summary'], 1200) : null,
            'inbox_thread' => $normalizedThread !== [] ? $normalizedThread : null,
            'latest_inbound' => Str::limit(trim($inboundBody), 1500),
            'sender_name' => $options['sender_name'] ?? null,
            'agent_notes' => isset($options['agent_notes']) ? Str::limit(trim((string) $options['agent_notes']), 1500) : null,
            'research_required' => (bool) ($options['research_required'] ?? false),
            'forbid_links' => (bool) ($options['forbid_links'] ?? false),
            'must_include_url' => isset($options['must_include_url']) && trim((string) $options['must_include_url']) !== ''
                ? trim((string) $options['must_include_url'])
                : null,
            'sender_offer' => '(use business_campaign_brief / agent_notes — do not invent products)',
        ];

        return $this->composeFromBrief($channel, 'inbox_reply', $brief);
    }

    /**
     * @param  array<string, mixed>  $brief
     * @return array{body:string, subject:?string}
     */
    private function composeFromBrief(string $channel, string $mode, array $brief): array
    {
        $composed = $this->composeWithLaravelAi($channel, $mode, $brief);
        if ($composed['body'] === '') {
            $composed = $this->composeWithOpenAiFallback($channel, $mode, $brief);
        }

        if ($channel === 'email' && $composed['body'] !== '') {
            $composed['body'] = EmailOutboundFormat::formatPlainBody($composed['body']);
            $research = is_string($brief['recipient_research'] ?? null) ? (string) $brief['recipient_research'] : null;
            $subject = trim((string) ($composed['subject'] ?? ''));
            if ($subject === '' || EmailOutboundFormat::isWeakSubject($subject)) {
                $composed['subject'] = $this->fallbackSubject($brief, $composed['body']);
            }
            $composed['subject'] = EmailOutboundFormat::normalizeSubject(
                (string) ($composed['subject'] ?? ''),
                $research,
            );
        }

        if ($channel !== 'email') {
            $composed['subject'] = null;
        }

        return $composed;
    }

    /**
     * @param  array<string, mixed>  $brief
     * @return array{body:string, subject:?string}
     */
    private function composeWithLaravelAi(string $channel, string $mode, array $brief): array
    {
        $chain = $this->providers->forAgent();
        if ($chain === []) {
            return ['body' => '', 'subject' => null];
        }

        try {
            $response = (new OutboundCopyAgent($mode, $channel))->prompt(
                "Write the message from this JSON context:\n".json_encode($brief, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                provider: $chain,
            );

            $structured = is_array($response->structured ?? null) ? $response->structured : [];
            $body = $this->cleanMessage((string) ($structured['body'] ?? ''));
            $subject = isset($structured['subject']) ? $this->cleanMessage((string) $structured['subject']) : '';

            return [
                'body' => $body,
                'subject' => $subject !== '' ? Str::limit($subject, 80, '') : null,
            ];
        } catch (\Throwable $e) {
            report($e);

            return ['body' => '', 'subject' => null];
        }
    }

    /**
     * @param  array<string, mixed>  $brief
     * @return array{body:string, subject:?string}
     */
    private function composeWithOpenAiFallback(string $channel, string $mode, array $brief): array
    {
        if (! $this->openai->isConfigured()) {
            return ['body' => '', 'subject' => null];
        }

        try {
            if ($mode === 'inbox_reply') {
                $thread = [];
                foreach (($brief['inbox_thread'] ?? []) as $row) {
                    $thread[] = [
                        'role' => $row['role'] ?? 'user',
                        'body' => $row['content'] ?? '',
                    ];
                }

                $body = $this->cleanMessage($this->openai->generateInboxReply(
                    $channel,
                    (string) ($brief['business_campaign_brief'] ?? ''),
                    $thread,
                    (string) ($brief['latest_inbound'] ?? ''),
                    (string) ($brief['recipient'] ?? 'contact'),
                    [
                        'campaign_name' => $brief['campaign_name'] ?? '',
                        'lead_headline' => $brief['lead_headline'] ?? null,
                        'thread_summary' => $brief['thread_summary'] ?? '',
                        'sender_name' => $brief['sender_name'] ?? '',
                        'agent_notes' => $brief['agent_notes'] ?? '',
                        'research_required' => (bool) ($brief['research_required'] ?? false),
                        'forbid_links' => (bool) ($brief['forbid_links'] ?? false),
                        'must_include_url' => $brief['must_include_url'] ?? '',
                    ],
                ));

                return ['body' => $body, 'subject' => null];
            }

            $body = $this->cleanMessage($this->openai->generateOutreachContent(
                'generate',
                $channel,
                $channel === 'email' ? 'send_email' : 'message',
                $channel === 'email' ? 'body' : 'message',
                "Mode: {$mode}\nContext:\n".json_encode($brief, JSON_UNESCAPED_UNICODE),
            ));

            $subject = null;
            if ($channel === 'email' && $body !== '') {
                $subject = $this->fallbackSubject($brief, $body);
            }

            return ['body' => $body, 'subject' => $subject];
        } catch (\Throwable $e) {
            report($e);

            return ['body' => '', 'subject' => null];
        }
    }

    /**
     * @param  array<string, mixed>  $brief
     */
    private function fallbackSubject(array $brief, string $body): string
    {
        $research = is_string($brief['recipient_research'] ?? null) ? (string) $brief['recipient_research'] : null;

        if ($this->openai->isConfigured()) {
            try {
                $subject = $this->cleanMessage($this->openai->generateOutreachContent(
                    'generate',
                    'email',
                    'send_email',
                    'subject',
                    'Write one converting email subject (4–9 words). Ground it in recipient research. '
                    .'Never use generic fillers like Quick note, Hello, Following up, Introduction. '
                    .'Prefer a concrete product/workflow observation over the sender brand name.'."\n"
                    .json_encode([
                        'research' => $research,
                        'recipient' => $brief['recipient'] ?? null,
                        'body_preview' => Str::limit($body, 400),
                    ], JSON_UNESCAPED_UNICODE),
                ));
                if ($subject !== '') {
                    return EmailOutboundFormat::normalizeSubject($subject, $research);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return EmailOutboundFormat::normalizeSubject('', $research);
    }

    private function cleanMessage(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:\w+)?\s*/', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;

        return trim($text);
    }

    /**
     * @param  array<string, mixed>  $identity
     */
    private function recipientLabel(array $identity): string
    {
        return (string) (
            $identity['display_name']
            ?? $identity['email']
            ?? $identity['linkedin_url']
            ?? (isset($identity['instagram_handle']) ? '@'.$identity['instagram_handle'] : null)
            ?? (isset($identity['telegram_handle']) ? '@'.$identity['telegram_handle'] : null)
            ?? (isset($identity['twitter_handle']) ? '@'.$identity['twitter_handle'] : null)
            ?? $identity['phone']
            ?? 'contact'
        );
    }
}

<?php

namespace App\V2\Ai\Services;

use App\Ai\Agents\InboxClassifyAgent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Inbound reply classification: Laravel AI structured agent first;
 * keywords only for empty/opt-out hard gates and emergency fallback.
 */
class InboxClassificationService
{
    /**
     * @return array{
     *     priority: string,
     *     intent: string,
     *     stage: string,
     *     recommended_action: string,
     *     evidence: list<string>,
     *     buying_signal: string,
     *     asset_preference: string|null,
     *     source?: string
     * }
     */
    public function classify(string $body): array
    {
        $text = Str::lower(trim($body));
        if ($text === '') {
            return $this->payload(
                priority: 'needs_judgment',
                intent: 'unclear',
                stage: 'early',
                recommendedAction: 'Review the thread and classify manually.',
                evidence: ['empty_message'],
            );
        }

        // Hard safety gate — never wait on the LLM for unsubscribe/stop.
        if (preg_match('/\b(unsubscribe|stop contacting|remove me|do not contact|don\'t contact|dont contact)\b/i', $text)
            || preg_match('/^(stop|unsubscribe)\b/i', $text)
        ) {
            return $this->payload(
                priority: 'low_priority',
                intent: 'opt_out',
                stage: 'closed_lost',
                recommendedAction: 'Pause outreach for this lead and confirm removal if required.',
                evidence: ['opt_out'],
            );
        }

        $structured = $this->classifyWithLaravelAi($body);
        if ($structured !== null) {
            return $structured;
        }

        return $this->classifyWithKeywords($body);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function classifyWithLaravelAi(string $body): ?array
    {
        $chain = app(AiProviderChain::class)->forAgent();
        if ($chain === []) {
            return null;
        }

        try {
            $response = (new InboxClassifyAgent)->prompt(
                "Classify this inbound reply:\n".Str::limit(trim($body), 2000),
                provider: $chain,
            );
            $raw = is_array($response->structured ?? null) ? $response->structured : [];
            if ($raw === []) {
                return null;
            }

            $intent = (string) ($raw['intent'] ?? '');
            if (! in_array($intent, InboxClassifyAgent::INTENTS, true)) {
                return null;
            }

            $evidence = $raw['evidence'] ?? [];
            if (! is_array($evidence)) {
                $evidence = [];
            }
            $evidence = array_values(array_filter(array_map(
                fn ($item) => is_string($item) ? trim($item) : '',
                $evidence,
            )));

            $payload = $this->payload(
                priority: (string) ($raw['priority'] ?? 'needs_judgment'),
                intent: $intent,
                stage: (string) ($raw['stage'] ?? 'engaged'),
                recommendedAction: trim((string) ($raw['recommended_action'] ?? 'Review reply and decide next step.')),
                evidence: $evidence !== [] ? $evidence : ['llm'],
                buyingSignal: (string) ($raw['buying_signal'] ?? 'none'),
                assetPreference: isset($raw['asset_preference']) && is_string($raw['asset_preference'])
                    ? $raw['asset_preference']
                    : null,
            );
            $payload['source'] = 'laravel_ai';

            return $payload;
        } catch (\Throwable $e) {
            Log::warning('[Soci] InboxClassifyAgent failed — keyword fallback', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Deterministic keyword path — emergency fallback + unit matrix.
     *
     * @return array{
     *     priority: string,
     *     intent: string,
     *     stage: string,
     *     recommended_action: string,
     *     evidence: list<string>,
     *     buying_signal: string,
     *     asset_preference: string|null
     * }
     */
    public function classifyWithKeywords(string $body): array
    {
        $text = Str::lower(trim($body));

        if ($text === '') {
            return $this->payload(
                priority: 'needs_judgment',
                intent: 'unclear',
                stage: 'early',
                recommendedAction: 'Review the thread and classify manually.',
                evidence: ['empty_message'],
            );
        }

        if (preg_match('/\b(unsubscribe|stop contacting|remove me|do not contact|don\'t contact|dont contact)\b/i', $text)
            || preg_match('/^(stop|unsubscribe)\b/i', $text)
        ) {
            return $this->payload(
                priority: 'low_priority',
                intent: 'opt_out',
                stage: 'closed_lost',
                recommendedAction: 'Pause outreach for this lead and confirm removal if required.',
                evidence: ['opt_out'],
            );
        }

        if (preg_match('/\b(schedule|book|calendar|zoom|meeting|hop on|let\'s talk|lets talk|call me|set up a call|setup a call|chat tomorrow|book a demo|schedule a demo|demo call|pick a time)\b/i', $text)) {
            return $this->payload(
                priority: 'hot',
                intent: 'meeting_request',
                stage: 'qualified',
                recommendedAction: 'Use book_meeting — last card. Send the booking / meeting link.',
                evidence: ['meeting'],
                buyingSignal: 'hard',
                assetPreference: 'meet',
            );
        }

        if (preg_match('/\b(webinar|watch|video|see how it works|show me how|walkthrough|walk through)\b/i', $text)
            || preg_match('/\bdemo\b/i', $text)
        ) {
            return $this->payload(
                priority: 'hot',
                intent: 'wants_watch',
                stage: 'engaged',
                recommendedAction: 'Share the webinar link (or sales page if no webinar is configured).',
                evidence: ['watch'],
                buyingSignal: 'hard',
                assetPreference: 'watch',
            );
        }

        $pricing = (bool) preg_match('/\b(pricing|price|cost|how much|quote|budget)\b/i', $text);
        if ($pricing
            || preg_match('/\b(tell me more|send (me )?(the )?(link|page|info)|sales page|how does (it|this) work|what (is|does) (it|this)|can you (send|share)|send it over)\b/i', $text)
        ) {
            $evidence = $pricing ? ['question', 'pricing'] : ['question'];

            return $this->payload(
                priority: $pricing ? 'hot' : 'needs_judgment',
                intent: 'wants_info',
                stage: 'engaged',
                recommendedAction: 'Share the sales page (one link). Do not add a meeting link yet.',
                evidence: $evidence,
                buyingSignal: 'hard',
                assetPreference: $pricing ? null : 'read',
            );
        }

        if (preg_match('/\b(i\'d like|i would like|we\'d like|we would like|want more|more predictable|sounds (useful|interesting)|that would be useful|how can you help|interested in|yes please|definitely|would love|let\'s do it|lets do it)\b/i', $text)) {
            return $this->payload(
                priority: 'hot',
                intent: 'interested',
                stage: 'engaged',
                recommendedAction: 'Permission earned — share sales page or webinar, then meeting if that does not convert.',
                evidence: ['yes'],
                buyingSignal: 'hard',
            );
        }

        if (preg_match('/\b(i(\'|’)ll (take a )?look|i will (take a )?look|i(\'|’)ll check|check it out|take a look|will review|look it over)\b/i', $text)) {
            return $this->payload(
                priority: 'needs_judgment',
                intent: 'will_review',
                stage: 'engaged',
                recommendedAction: 'If they do not have the page/webinar yet, share the configured asset. If they already received it, they are stalling — offer a meeting.',
                evidence: ['will_review'],
                buyingSignal: 'soft',
            );
        }

        if (preg_match('/\b(not sure|still thinking|need to think|let me think|think about it|doesn\'t answer|doesnt answer|not convinced|looked at (it|the)|i saw (it|the page|the deck)|still digesting|need to talk to|run it by|hesitat|cold feet|overwhelming)\b/i', $text)) {
            return $this->payload(
                priority: 'needs_judgment',
                intent: 'not_convinced',
                stage: 'engaged',
                recommendedAction: 'Asset likely did not convert — offer a short meeting as the last card.',
                evidence: ['not_convinced'],
                buyingSignal: 'soft',
            );
        }

        if (preg_match('/\b(referrals?|outbound|inbound|word of mouth|organic|pipeline|most(ly)? (our )?(new )?clients|we (do|don\'t|dont|are|aren\'t|arent)|not really|haven\'t found|havent found)\b/i', $text)) {
            return $this->payload(
                priority: 'needs_judgment',
                intent: 'qualifying_answer',
                stage: 'early',
                recommendedAction: 'Ask whether a more predictable flow of opportunities matters. No links yet.',
                evidence: ['qualifying_answer'],
                buyingSignal: 'soft',
            );
        }

        if (preg_match('/\b(not now|maybe later|circle back|next quarter|busy right now)\b/i', $text)) {
            return $this->payload(
                priority: 'low_priority',
                intent: 'timing',
                stage: 'engaged',
                recommendedAction: 'Consider nurture sequence or pause active outreach.',
                evidence: ['not_now'],
                buyingSignal: 'none',
            );
        }

        if (preg_match('/\b(already use|using .+ already|not a fit|wrong person|not interested)\b/i', $text)) {
            return $this->payload(
                priority: 'low_priority',
                intent: 'objection',
                stage: 'engaged',
                recommendedAction: 'Acknowledge and leave the door open. No pitch or links.',
                evidence: ['objection'],
                buyingSignal: 'none',
            );
        }

        if (preg_match('/\b(yes|yeah|yep|sure|interested|sounds good)\b/i', $text)) {
            return $this->payload(
                priority: 'hot',
                intent: 'interested',
                stage: 'engaged',
                recommendedAction: 'Soft yes — ask one qualifying question before any link.',
                evidence: ['yes'],
                buyingSignal: 'soft',
            );
        }

        if (preg_match('/\b(question|how does|what is|can you|tell me)\b/i', $text)) {
            return $this->payload(
                priority: 'needs_judgment',
                intent: 'question',
                stage: 'engaged',
                recommendedAction: 'Answer the question, then one next step — still no meeting unless they ask.',
                evidence: ['question'],
                buyingSignal: 'soft',
            );
        }

        return $this->payload(
            priority: 'needs_judgment',
            intent: 'neutral',
            stage: 'early',
            recommendedAction: 'Review reply and decide next step.',
            evidence: ['neutral_tone'],
        );
    }

    public function priorityLabel(string $priority): string
    {
        return match ($priority) {
            'hot' => 'Hot — strong buying signal',
            'low_priority' => 'Low priority — objection or timing',
            default => 'Needs judgment',
        };
    }

    /**
     * @param  list<string>  $evidence
     * @return array{
     *     priority: string,
     *     intent: string,
     *     stage: string,
     *     recommended_action: string,
     *     evidence: list<string>,
     *     buying_signal: string,
     *     asset_preference: string|null
     * }
     */
    private function payload(
        string $priority,
        string $intent,
        string $stage,
        string $recommendedAction,
        array $evidence,
        string $buyingSignal = 'none',
        ?string $assetPreference = null,
    ): array {
        return [
            'priority' => $priority,
            'intent' => $intent,
            'stage' => $stage,
            'recommended_action' => $recommendedAction,
            'evidence' => $evidence,
            'buying_signal' => $buyingSignal,
            'asset_preference' => $assetPreference,
        ];
    }
}

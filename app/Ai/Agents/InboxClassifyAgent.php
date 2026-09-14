<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Structured inbound-reply classifier for Unified Inbox / conversion ladder.
 */
class InboxClassifyAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public const INTENTS = [
        'meeting_request',
        'wants_watch',
        'wants_info',
        'interested',
        'will_review',
        'not_convinced',
        'qualifying_answer',
        'question',
        'timing',
        'objection',
        'opt_out',
        'neutral',
    ];

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
Classify one inbound reply to sales outreach. Infer meaning from the message — not keyword lists.

intent meanings:
- meeting_request: wants a call/demo/booking/calendar
- wants_watch: wants webinar, walkthrough, video, see how it works
- wants_info: wants sales page, pricing, how it works, send the link/info
- interested: soft/hard interest without a clear asset ask yet
- will_review: will look / check / review later
- not_convinced: hesitation after seeing something; needs meeting as last card
- qualifying_answer: answering a discovery question about their process (referrals, outbound, etc.) — NOT a pitch ask
- question: other clarifying question
- timing: not now / later / busy
- objection: not interested / wrong person / already using something
- opt_out: unsubscribe / stop contacting
- neutral: none of the above

buying_signal: none | soft | hard
asset_preference: read (sales page) | watch (webinar) | meet (booking) | null

priority: hot | needs_judgment | low_priority
stage: early | engaged | qualified | closed_lost
recommended_action: one short operator guidance line (not prospect-facing copy)
evidence: short tags describing why you chose this (e.g. meeting, pricing, opt_out)
PROMPT;
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'intent' => $schema->string()->enum(self::INTENTS)->required(),
            'buying_signal' => $schema->string()->enum(['none', 'soft', 'hard'])->required(),
            'asset_preference' => $schema->string()->enum(['read', 'watch', 'meet'])->nullable()->required(),
            'priority' => $schema->string()->enum(['hot', 'needs_judgment', 'low_priority'])->required(),
            'stage' => $schema->string()->enum(['early', 'engaged', 'qualified', 'closed_lost'])->required(),
            'recommended_action' => $schema->string()->required(),
            'evidence' => $schema->array()->items($schema->string())->required(),
        ];
    }

    public function timeout(): int
    {
        return 30;
    }
}

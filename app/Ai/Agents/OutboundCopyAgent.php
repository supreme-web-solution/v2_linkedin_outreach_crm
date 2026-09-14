<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * One outbound-copy brain for cold email, DMs, campaign first-touch, follow-ups, and inbox replies.
 * Principles only — channel format is in the brief; no phrase blacklists or fixed word counts.
 */
class OutboundCopyAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(
        private readonly string $mode = 'outbound',
        private readonly string $channel = 'email',
    ) {}

    public function instructions(): Stringable|string
    {
        $channel = strtolower(trim($this->channel));
        $mode = strtolower(trim($this->mode));

        $channelGuidance = match ($channel) {
            'email' => implode("\n", [
                'Channel is EMAIL: write a real email a busy operator will finish reading.',
                'SUBJECT (required): plan a converting subject — specific to THEIR product/workflow from research; 4–9 words; no generic filler ("Quick note", "Hello", "Following up", "Introduction"). Prefer a concrete observation or named workflow (e.g. evidence-to-release, milestone escrow) over your product name.',
                'BODY FORMAT (required): use blank lines between short paragraphs. Structure:',
                '1) Greeting on its own line (Hi {Name},)',
                '2) One short paragraph with a verified observation from research',
                '3) One short paragraph connecting that observation to a relevant operational implication',
                '4) One short soft-value bridge using ONLY sender_offer / proof in the brief (no invented metrics)',
                '5) One clear reply-earning question as its own paragraph',
                'Do not dump everything into a single block. Do not use markdown bullets unless the brief asks. Soft value is fine; hard pitch and calendar links are not for first cold email unless the brief requires it.',
            ]),
            'linkedin' => 'Channel is LinkedIn DM: concise, professional, conversational. Earn a reply. No hard pitch, no calendar link on first touch unless the brief requires it.',
            'instagram', 'whatsapp', 'telegram', 'twitter' => 'Channel is a short chat DM ('.$channel.'): casual, like texting. Earn a reply. No hard pitch, no calendar link unless the brief requires it. Phone/handle-only contacts often have no research — that is normal; do not invent personalization.',
            default => 'Adapt naturally to channel '.$channel.'.',
        };

        $modeGuidance = match ($mode) {
            'first_touch' => 'Mode: FIRST TOUCH outbound. Open the relationship. For non-email DMs, prefer a situational question over a product pitch.',
            'follow_up' => 'Mode: FOLLOW-UP. Reference prior outreach naturally, then one short context-aware question grounded in their world. Never write empty check-ins or “just bumping” filler. Do not restart the pitch from scratch.',
            'inbox_reply' => 'Mode: INBOX REPLY in an active thread. Respond to what they actually said. Never write operator instructions ("Reply with…"). Never invent facts. Match thread tone. For early email replies, prefer 2–4 short paragraphs with substance — not a chat bubble.',
            'personalized' => 'Mode: PERSONALIZED outbound grounded ONLY in evidence in the brief. Do not invent employers, metrics, or prior conversations.',
            default => 'Mode: cold/one-shot outbound. If prior_draft_to_improve is set, rewrite using research — do not keep vague wording.',
        };

        return implode("\n", [
            'You write one message a real prospect will read for a sales Command Center.',
            $channelGuidance,
            $modeGuidance,
            'Honor owner_brief / handoff intent: if they ask for a simple greeting or short opener and recipient_research is empty, write a brief honest greeting that invites a reply. Do not refuse by returning empty text, and do not invent company-specific personalization.',
            'Never invent products beyond sender_offer / offer_override.',
            'Never invent facts not in recipient_research / evidence / thread.',
            'When copy_prefs is present: honor tone, preferred_angle, style_notes, and do_not_say; use proof_points only when relevant to their situation (never invent metrics).',
            'Use recent_thread / inbox_thread only as conversation memory.',
            'Return structured fields only: body (required message text), subject (email subject when channel is email — required and converting; otherwise null).',
            'body must be ONLY the prospect-facing message — no markdown fences, no subject line inside body, no operator notes.',
            'For email body: separate paragraphs with a blank line (\\n\\n). Never return one unbroken wall of text.',
        ]);
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'body' => $schema->string()->required(),
            'subject' => $schema->string()->nullable()->required(),
        ];
    }

    public function timeout(): int
    {
        return (int) config('socifusion_ai.web_research.timeout', 45);
    }
}

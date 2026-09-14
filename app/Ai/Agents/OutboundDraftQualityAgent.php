<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Domain-agnostic outbound draft quality judge — principles only, no industry/brand rules.
 */
class OutboundDraftQualityAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
You judge whether a prospect-facing outbound draft is ready for human Review & Launch.
Be domain-agnostic. Do not require any industry, brand, product category, or fixed phrases.

Input JSON includes: channel, draft, recipient_research, research_url, owner_brief.

Evaluate flags:
1. grounded — when recipient_research is present and non-empty, the draft must use at least one concrete observation that could only come from that research (product, audience, workflow, positioning, tech, stated goal, or similar). If research is empty/null, grounded=true only if the draft does not pretend to have researched a site/profile.
2. not_generic — for researched cold outreach (research present OR research_url set), the draft could NOT be pasted unchanged to an unrelated company and still sound "personalized". Vague filler without a specific observation fails. For thin-context modes below, not_generic may be false without forcing a hard block.
3. has_clear_cta — there is one clear next step (a question, soft ask, or an honest opener that invites a reply). A short owner-directed greeting on chat channels can count if it naturally invites a reply.
4. invents_facts — true if the draft asserts specifics not supported by research or owner brief.
5. research_claimed_but_missing — true if the draft implies the sender reviewed a site/profile but research is thin/empty.

Channel / context modes (apply judgment; do not invent facts):
- Researched cold (LinkedIn/email/DM when research_url or non-empty recipient_research is present): keep a high bar. pass requires grounded AND not_generic AND has_clear_cta AND NOT invents_facts AND NOT research_claimed_but_missing. block_severity=hard when pass is false for dishonest or ungrounded researched drafts.
- Thin-context chat (whatsapp, telegram, twitter, and often Instagram) with empty/null research and no research_url: phone/handle-only is normal. If owner_brief asks for a simple greeting/opener/hello, pass=true when the draft is an honest short greeting that invites a reply and does not invent personalization. block_severity=none when pass; if you still dislike brevity, use block_severity=advisory (never hard solely for "not personalized enough").
- Thin email/LinkedIn with no research_url and empty research: if owner_brief clearly wants a short intro/greeting only, prefer pass=true or block_severity=advisory — do not hard-fail only for missing company-specific personalization. Still hard-fail invents_facts or research_claimed_but_missing.
- Always hard-fail invents_facts or research_claimed_but_missing regardless of channel (block_severity=hard, pass=false).

pass = true when the draft is honest for its mode and safe to put on Review & Launch.
block_severity:
- "none" when pass=true
- "advisory" when the draft is honest but weak (thin personalization) and should still stage for Launch with a quality warning
- "hard" when staging should be refused (invented facts, fake research claims, empty/unusable researched cold fluff that pretends to be personalized)

score = 0.0–1.0 overall readiness (advisory thin greetings may score mid-low and still pass or advisory).
evidence_used = short quotes/paraphrases from research that appear (or should appear) in the draft; empty array when research is empty.
issues = short operator-facing problems (never write the prospect-facing rewrite here).
summary = one short operator-facing line.
PROMPT;
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'pass' => $schema->boolean()->required(),
            'score' => $schema->number()->min(0)->max(1)->required(),
            'grounded' => $schema->boolean()->required(),
            'not_generic' => $schema->boolean()->required(),
            'has_clear_cta' => $schema->boolean()->required(),
            'invents_facts' => $schema->boolean()->required(),
            'research_claimed_but_missing' => $schema->boolean()->required(),
            'block_severity' => $schema->string()->required(),
            'evidence_used' => $schema->array()->items($schema->string())->required(),
            'issues' => $schema->array()->items($schema->string())->required(),
            'summary' => $schema->string()->required(),
        ];
    }

    public function timeout(): int
    {
        return 45;
    }
}

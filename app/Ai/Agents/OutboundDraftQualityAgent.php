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

Evaluate:
1. grounded — when recipient_research is present and non-empty, the draft must use at least one concrete observation that could only come from that research (product, audience, workflow, positioning, tech, stated goal, or similar). If research is empty/null, grounded=true only if the draft does not pretend to have researched a site.
2. not_generic — the draft could NOT be pasted unchanged to an unrelated company and still sound "personalized". Vague filler without a specific observation fails.
3. has_clear_cta — there is one clear next step (a question or soft ask), not a hard multi-CTA dump.
4. invents_facts — true if the draft asserts specifics not supported by research or owner brief.
5. research_claimed_but_missing — true if the draft implies the sender reviewed a site/profile but research is thin/empty.

pass = grounded AND not_generic AND has_clear_cta AND NOT invents_facts AND NOT research_claimed_but_missing.

score = 0.0–1.0 overall readiness.
evidence_used = short quotes/paraphrases from research that appear (or should appear) in the draft.
issues = short operator-facing problems (never write the prospect-facing rewrite here).
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

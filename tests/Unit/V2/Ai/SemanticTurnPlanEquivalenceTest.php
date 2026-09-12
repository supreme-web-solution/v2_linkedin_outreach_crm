<?php

namespace Tests\Unit\V2\Ai;

use App\V2\Ai\Services\SemanticTurnPlanNormalizer;
use App\V2\Ai\Services\ToolPolicyGateService;
use Tests\TestCase;

/**
 * Validates semantic plan → enforcement equivalence without regex or live LLM.
 * Fixtures represent the meaning the LLM semantic planner should produce.
 */
class SemanticTurnPlanEquivalenceTest extends TestCase
{
    private SemanticTurnPlanNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = app(SemanticTurnPlanNormalizer::class);
    }

    public function test_discovery_phrasing_variants_share_semantic_fingerprint(): void
    {
        $canonical = $this->discoverySemantic(50);
        $canonicalFp = $this->normalizer->equivalenceFingerprint($canonical);

        foreach ([
            $this->discoverySemantic(50, segment: 'SaaS founders'),
            $this->discoverySemantic(50, entity: 'companies', segment: 'SaaS startup founders'),
            $this->discoverySemantic(50, segment: 'SaaS founders we could target'),
        ] as $variant) {
            $this->assertSame(
                $canonicalFp,
                $this->normalizer->equivalenceFingerprint($variant),
                'Discovery variants should share semantic fingerprint',
            );
        }

        $enforcement = $this->normalizer->toEnforcementPlan($canonical, 'sample');
        $this->assertSame('find_only', $enforcement['required_outcome']);
        $this->assertSame('mutate_allowed', $enforcement['side_effect_budget']);
        $this->assertSame(50, $enforcement['measurable_expectations']['target_count']);
    }

    public function test_exclusion_phrasing_variants_share_constraint(): void
    {
        $contacted = $this->outreachSemantic(100, excludeContacted: true);
        $approached = $this->outreachSemantic(100, excludeContacted: true, segment: 'already approached');

        $this->assertSame(
            $this->normalizer->equivalenceFingerprint($contacted),
            $this->normalizer->equivalenceFingerprint($approached),
        );

        $plan = $this->normalizer->toEnforcementPlan($contacted, 'sample');
        $this->assertTrue($plan['constraints']['exclude_contacted'] ?? false);
    }

    public function test_novel_compound_prepare_outreach_plan(): void
    {
        $semantic = [
            'user_objective' => 'prepare_outreach',
            'target_entity' => 'companies',
            'target_segment' => 'potential customers for our service',
            'quantity' => null,
            'new_only' => false,
            'exclude_previously_contacted' => true,
            'decision_maker_required' => true,
            'preferred_channel' => 'whatsapp',
            'geography' => null,
            'schedule_hint' => 'tomorrow',
            'data_preference' => 'reuse_existing_first',
            'execution_mode' => 'prepare_outreach',
            'prepare_only' => true,
            'send_requested' => false,
            'delete_requested' => false,
            'requires_clarification' => false,
            'clarification_reason' => null,
            'ambiguous_referent' => null,
            'confidence' => 0.88,
        ];

        $plan = $this->normalizer->toEnforcementPlan($semantic, 'novel compound request');
        $this->assertSame('setup_only', $plan['required_outcome']);
        $this->assertSame('prepare_only', $plan['side_effect_budget']);
        $this->assertSame('whatsapp', $plan['constraints']['preferred_channel'] ?? null);
        $this->assertTrue($plan['constraints']['exclude_contacted'] ?? false);
        $this->assertTrue($plan['constraints']['decision_maker_required'] ?? false);
    }

    public function test_read_only_state_queries_block_mutations(): void
    {
        $gate = app(ToolPolicyGateService::class);

        foreach ([
            'What campaigns did you create today?',
            'What did you create today?',
            'Show me our SaaS prospects.',
            'Which prospects have WhatsApp?',
        ] as $message) {
            $semantic = [
                'user_objective' => 'report_state',
                'target_entity' => 'activity',
                'target_segment' => null,
                'quantity' => null,
                'new_only' => false,
                'exclude_previously_contacted' => false,
                'decision_maker_required' => false,
                'preferred_channel' => str_contains(strtolower($message), 'whatsapp') ? 'whatsapp' : null,
                'geography' => null,
                'schedule_hint' => null,
                'data_preference' => 'unspecified',
                'execution_mode' => 'read_only',
                'prepare_only' => false,
                'send_requested' => false,
                'delete_requested' => false,
                'requires_clarification' => false,
                'clarification_reason' => null,
                'ambiguous_referent' => null,
                'confidence' => 0.9,
            ];

            $plan = $this->normalizer->toEnforcementPlan($semantic, $message);
            $this->assertSame('read_only', $plan['side_effect_budget'], $message);

            foreach (['discover_prospects', 'draft_campaign_plan', 'delete_campaign', 'send_inbox_reply'] as $tool) {
                $this->assertFalse(
                    $gate->check($tool, $plan)['allowed'],
                    "{$tool} must be blocked for read-only: {$message}",
                );
            }

            $this->assertTrue($gate->check('search_prospects', $plan)['allowed']);
            $this->assertTrue($gate->check('search_activity', $plan)['allowed']);
        }
    }

    public function test_ambiguous_campaign_referent_is_clarify_and_mutation_proof(): void
    {
        $gate = app(ToolPolicyGateService::class);

        foreach (['Send the campaign.', 'Delete that campaign.'] as $message) {
            $semantic = [
                'user_objective' => 'manage_resources',
                'target_entity' => 'campaigns',
                'target_segment' => null,
                'quantity' => null,
                'new_only' => false,
                'exclude_previously_contacted' => false,
                'decision_maker_required' => false,
                'preferred_channel' => null,
                'geography' => null,
                'schedule_hint' => null,
                'data_preference' => 'unspecified',
                'execution_mode' => 'clarify',
                'prepare_only' => false,
                'send_requested' => str_starts_with($message, 'Send'),
                'delete_requested' => str_starts_with($message, 'Delete'),
                'requires_clarification' => true,
                'clarification_reason' => 'Multiple campaigns could match; user must specify which one.',
                'ambiguous_referent' => 'campaign',
                'confidence' => 0.4,
            ];

            $plan = $this->normalizer->toEnforcementPlan($semantic, $message);
            $this->assertSame('clarify', $plan['required_outcome']);
            $this->assertFalse($gate->check('activate_outreach_campaign', $plan)['allowed']);
            $this->assertFalse($gate->check('delete_campaign', $plan)['allowed']);
        }
    }

    public function test_new_only_and_whatsapp_outreach_semantics(): void
    {
        $semantic = $this->outreachSemantic(100, newOnly: true, channel: 'whatsapp');
        $plan = $this->normalizer->toEnforcementPlan($semantic, 'sample');

        $this->assertSame('send_now', $plan['required_outcome']);
        $this->assertTrue($plan['constraints']['new_only'] ?? false);
        $this->assertSame('whatsapp', $plan['constraints']['preferred_channel'] ?? null);
    }

    public function test_discovery_semantic_equivalence_across_phrasings(): void
    {
        $variants = [
            $this->discoverySemantic(50, segment: 'SaaS founders'),
            $this->discoverySemantic(50, segment: 'SaaS founders'),
            $this->discoverySemantic(50, entity: 'founders', segment: 'SaaS startup founders'),
            $this->discoverySemantic(50, segment: 'SaaS founders we could potentially target'),
        ];

        $canonical = $this->materialDiscoverySignature($variants[0]);

        foreach ($variants as $index => $variant) {
            $this->assertSame(
                $canonical,
                $this->materialDiscoverySignature($variant),
                "Discovery variant {$index} should be materially equivalent",
            );

            $plan = $this->normalizer->toEnforcementPlan($variant, 'sample');
            $this->assertSame('find_only', $plan['required_outcome']);
            $this->assertSame('mutate_allowed', $plan['side_effect_budget']);
            $this->assertSame(50, $plan['measurable_expectations']['target_count']);
            $this->assertFalse($plan['constraints']['send_requested'] ?? false);
        }
    }

    public function test_contextual_identify_existing_records_stays_read_only(): void
    {
        $gate = app(ToolPolicyGateService::class);

        $semantic = [
            'user_objective' => 'report_state',
            'target_entity' => 'prospects',
            'target_segment' => null,
            'quantity' => null,
            'new_only' => false,
            'exclude_previously_contacted' => false,
            'decision_maker_required' => false,
            'preferred_channel' => 'whatsapp',
            'geography' => null,
            'schedule_hint' => null,
            'data_preference' => 'reuse_existing_first',
            'execution_mode' => 'read_only',
            'prepare_only' => false,
            'send_requested' => false,
            'delete_requested' => false,
            'requires_clarification' => false,
            'clarification_reason' => null,
            'ambiguous_referent' => null,
            'confidence' => 0.92,
        ];

        $plan = $this->normalizer->toEnforcementPlan($semantic, 'Identify which of our prospects have WhatsApp.');
        $this->assertSame('status_only', $plan['required_outcome']);
        $this->assertSame('read_only', $plan['side_effect_budget']);

        foreach (['discover_prospects', 'save_contacts', 'draft_campaign_plan', 'activate_outreach_campaign', 'delete_campaign'] as $tool) {
            $this->assertFalse($gate->check($tool, $plan)['allowed'], "{$tool} must stay blocked for contextual identify");
        }
    }

    public function test_compound_find_and_contact_normalizes_to_outreach(): void
    {
        $gate = app(ToolPolicyGateService::class);

        $semantic = [
            'user_objective' => 'discover_prospects',
            'target_entity' => 'founders',
            'target_segment' => 'SaaS',
            'quantity' => 100,
            'new_only' => true,
            'exclude_previously_contacted' => false,
            'decision_maker_required' => false,
            'preferred_channel' => null,
            'geography' => null,
            'schedule_hint' => null,
            'data_preference' => 'new_only',
            'execution_mode' => 'find_and_save',
            'prepare_only' => false,
            'send_requested' => true,
            'delete_requested' => false,
            'requires_clarification' => false,
            'clarification_reason' => null,
            'ambiguous_referent' => null,
            'confidence' => 0.95,
        ];

        $plan = $this->normalizer->toEnforcementPlan($semantic, 'Find 100 NEW SaaS founders and contact them.');
        $this->assertSame('send_now', $plan['required_outcome']);
        $this->assertSame('external_send_allowed', $plan['side_effect_budget']);
        $this->assertSame(100, $plan['measurable_expectations']['target_count']);
        $this->assertTrue($plan['constraints']['new_only'] ?? false);
        $this->assertTrue($plan['constraints']['send_requested'] ?? false);
        $this->assertTrue($gate->check('discover_prospects', $plan)['allowed']);
        $this->assertTrue($gate->check('activate_outreach_campaign', $plan)['allowed']);
    }

    public function test_reuse_existing_outreach_semantics(): void
    {
        $semantic = [
            'user_objective' => 'execute_outreach',
            'target_entity' => 'prospects',
            'target_segment' => 'SaaS founders we already have',
            'quantity' => null,
            'new_only' => false,
            'exclude_previously_contacted' => false,
            'decision_maker_required' => false,
            'preferred_channel' => null,
            'geography' => null,
            'schedule_hint' => null,
            'data_preference' => 'reuse_existing_first',
            'execution_mode' => 'send_outreach',
            'prepare_only' => false,
            'send_requested' => true,
            'delete_requested' => false,
            'requires_clarification' => false,
            'clarification_reason' => null,
            'ambiguous_referent' => null,
            'confidence' => 0.85,
        ];

        $plan = $this->normalizer->toEnforcementPlan($semantic, 'reuse existing');
        $this->assertSame('send_now', $plan['required_outcome']);
        $this->assertTrue($plan['constraints']['reuse_first'] ?? false);
    }

    /**
     * Material discovery signature — important fields only, not incidental wording.
     *
     * @param  array<string, mixed>  $semantic
     * @return array<string, mixed>
     */
    private function materialDiscoverySignature(array $semantic): array
    {
        $semantic = $this->normalizer->sanitizeSemantic($semantic);

        return [
            'user_objective' => $semantic['user_objective'],
            'quantity' => $semantic['quantity'],
            'execution_mode' => $semantic['execution_mode'],
            'send_requested' => $semantic['send_requested'],
            'data_preference' => $semantic['data_preference'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function discoverySemantic(int $quantity, string $entity = 'prospects', string $segment = 'SaaS founders'): array
    {
        return [
            'user_objective' => 'discover_prospects',
            'target_entity' => $entity,
            'target_segment' => $segment,
            'quantity' => $quantity,
            'new_only' => false,
            'exclude_previously_contacted' => false,
            'decision_maker_required' => false,
            'preferred_channel' => null,
            'geography' => null,
            'schedule_hint' => null,
            'data_preference' => 'discover_new',
            'execution_mode' => 'find_and_save',
            'prepare_only' => false,
            'send_requested' => false,
            'delete_requested' => false,
            'requires_clarification' => false,
            'clarification_reason' => null,
            'ambiguous_referent' => null,
            'confidence' => 0.9,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function outreachSemantic(
        int $quantity,
        bool $newOnly = false,
        bool $excludeContacted = false,
        ?string $channel = null,
        string $segment = 'SaaS companies',
    ): array {
        return [
            'user_objective' => 'execute_outreach',
            'target_entity' => 'companies',
            'target_segment' => $segment,
            'quantity' => $quantity,
            'new_only' => $newOnly,
            'exclude_previously_contacted' => $excludeContacted,
            'decision_maker_required' => false,
            'preferred_channel' => $channel,
            'geography' => null,
            'schedule_hint' => null,
            'data_preference' => $newOnly ? 'new_only' : 'discover_new',
            'execution_mode' => 'send_outreach',
            'prepare_only' => false,
            'send_requested' => true,
            'delete_requested' => false,
            'requires_clarification' => false,
            'clarification_reason' => null,
            'ambiguous_referent' => null,
            'confidence' => 0.9,
        ];
    }
}

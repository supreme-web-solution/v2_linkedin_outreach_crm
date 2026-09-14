<?php

namespace Tests\Unit\V2\Ai;

use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\Models\V2OutreachCampaign;
use App\V2\Ai\Services\CommandCenterService;
use App\V2\Ai\Services\OutboundCopyPrefsService;
use App\V2\Ai\Services\PlanSequenceNodeBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ColdOutboundPhaseBdTest extends TestCase
{
    use RefreshDatabase;

    public function test_copy_prefs_merge_and_learn_from_launch(): void
    {
        [$user, $org] = $this->seedOrg();
        $prefs = app(OutboundCopyPrefsService::class);

        $prefs->merge($user, $org->id, [
            'tone' => 'direct',
            'proof_points' => [
                [
                    'title' => 'Ops rollout',
                    'outcome' => 'Cut handoff time',
                    'industry' => 'B2B SaaS',
                ],
            ],
            'do_not_say' => ['guaranteed ROI'],
        ]);

        $settings = app(\App\V2\Ai\Services\AiEmployeeSettingsService::class)->for($user, $org->id);
        $block = $prefs->briefBlock($settings);

        $this->assertSame('direct', $block['tone'] ?? null);
        $this->assertSame(['guaranteed ROI'], $block['do_not_say'] ?? null);
        $this->assertNotEmpty($block['proof_points'] ?? []);

        $prefs->learnFromLaunchedPlan($user, $org->id, [
            'offer_override' => 'Focus on onboarding speed',
            'message_correction' => true,
        ]);

        $updated = $prefs->for(app(\App\V2\Ai\Services\AiEmployeeSettingsService::class)->for($user, $org->id));
        $this->assertSame('Focus on onboarding speed', $updated['preferred_angle']);
        $this->assertStringContainsString('Owner edited', (string) $updated['style_notes']);
    }

    public function test_follow_up_sequence_defaults_are_personalized_not_check_in(): void
    {
        $resolved = app(PlanSequenceNodeBuilder::class)->resolve([
            'type' => 'campaign',
            'primary_channel' => 'email',
            'single_channel_only' => true,
            'sequence' => [
                'Personalized first email',
                'Follow-up if no reply',
            ],
        ]);

        $json = json_encode($resolved['node_model']);
        $this->assertStringNotContainsString('just checking in', strtolower((string) $json));
        $this->assertStringNotContainsString('just bumping', strtolower((string) $json));

        $followUps = collect($resolved['node_model'])
            ->where('type', 'action')
            ->filter(fn ($n) => str_contains(strtolower((string) ($n['label'] ?? '')), 'follow'));

        $this->assertNotEmpty($followUps);
        foreach ($followUps as $node) {
            $config = is_array($node['config'] ?? null) ? $node['config'] : [];
            $this->assertTrue((bool) ($config['personalize_before_send'] ?? false), json_encode($node));
            $this->assertSame('', trim((string) ($config['body'] ?? '')));
            $this->assertSame('', trim((string) ($config['message'] ?? '')));
        }

        $presets = V2OutreachCampaign::templates();
        $blob = strtolower(json_encode($presets) ?: '');
        $this->assertStringNotContainsString('just checking in', $blob);
        $this->assertStringNotContainsString('just bumping', $blob);
    }

    public function test_draft_reply_card_shows_inbox_intel(): void
    {
        $card = app(CommandCenterService::class)->formatPlanCard([
            'type' => 'draft_reply',
            'goal' => 'Send inbox reply to Ada',
            'prospect_name' => 'Ada',
            'inbound_preview' => 'Can you send the pricing page?',
            'draft_text' => 'Happy to — here is the page.',
            'conversion_action' => 'share_sales_page',
            'inbox_intel' => [
                'intent' => 'interested',
                'next_step' => 'share sales page',
                'dossier_fact' => 'Runs a mid-market ops team',
            ],
        ], 7, 'web');

        $this->assertStringContainsString('Inbox reply — Review & Launch', $card);
        $this->assertStringContainsString('interested', $card);
        $this->assertStringContainsString('share sales page', $card);
        $this->assertStringContainsString('mid-market ops team', $card);
        $this->assertStringContainsString('awaiting approval (not sent)', $card);
    }

    public function test_quality_card_flags_include_research_and_cta(): void
    {
        $card = app(CommandCenterService::class)->formatPlanCard([
            'type' => 'campaign',
            'source' => 'cold_outbound',
            'one_shot' => true,
            'audience' => 'hello@example.com',
            'primary_channel' => 'email',
            'research_url' => 'https://example.com',
            'research_facts' => ['Builds tooling for ops teams'],
            'message' => 'Noticed your ops tooling focus — open to a quick chat?',
            'quality' => [
                'pass' => true,
                'score' => 0.9,
                'research_ok' => true,
                'grounded' => true,
                'not_generic' => true,
                'has_clear_cta' => true,
                'summary' => 'Ready',
            ],
        ], 9, 'web');

        $this->assertStringContainsString('research ok', $card);
        $this->assertStringContainsString('clear CTA', $card);
        $this->assertStringContainsString('pass', $card);
    }

    /**
     * @return array{0:User,1:V2Organization}
     */
    private function seedOrg(): array
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'BD Org',
            'slug' => 'bd-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        return [$user, $org];
    }
}

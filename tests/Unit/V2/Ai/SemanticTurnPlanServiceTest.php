<?php

namespace Tests\Unit\V2\Ai;

use App\Ai\Agents\SemanticTurnPlanAgent;
use App\Models\AiConversation;
use App\Models\AiEmployeeSetting;
use App\Models\AiMessage;
use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Ai\Services\SemanticTurnPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SemanticTurnPlanServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_planner_input_includes_thread_and_workspace_icp(): void
    {
        [$user, $org, $conversation] = $this->seedThread();

        $input = app(SemanticTurnPlanService::class)->plannerInput(
            $user,
            $org->id,
            'go ahead, just 10',
            $conversation,
        );

        $this->assertSame('go ahead, just 10', $input['user_message']);
        $this->assertFalse($input['pending_plans_waiting']);
        $this->assertCount(4, $input['thread']);
        $this->assertSame('user', $input['thread'][0]['role']);
        $this->assertStringContainsString('instagram', strtolower($input['thread'][0]['content']));
        $this->assertStringContainsString('CTO', (string) ($input['thread'][3]['content'] ?? ''));
        $this->assertSame('CTOs needing custom software', $input['workspace']['icp']['decision_maker'] ?? null);
    }

    public function test_interpret_sends_thread_to_llm_not_the_utterance_alone(): void
    {
        [$user, $org, $conversation] = $this->seedThread();

        config()->set('ai.providers.openai.key', 'sk-test');
        config()->set('socifusion_ai.model_failover', ['openai' => null]);
        config()->set('socifusion_ai.semantic_turn_planner', true);

        SemanticTurnPlanAgent::fake([
            [
                'user_objective' => 'discover_prospects',
                'target_entity' => 'prospects',
                'target_segment' => 'CTOs needing custom software',
                'quantity' => 10,
                'new_only' => false,
                'exclude_previously_contacted' => false,
                'decision_maker_required' => true,
                'preferred_channel' => null,
                'preferred_channels' => [],
                'channel_scope' => 'unspecified',
                'geography' => null,
                'schedule_hint' => null,
                'data_preference' => 'discover_new',
                'execution_mode' => 'find_and_save',
                'prepare_only' => false,
                'send_requested' => false,
                'delete_requested' => false,
                'cold_one_shot' => false,
                'recipient_correction' => false,
                'message_correction' => false,
                'offer_override' => null,
                'inbox_reply' => false,
                'audience_intent' => 'discover',
                'audience_ref' => null,
                'requires_clarification' => false,
                'clarification_reason' => null,
                'ambiguous_referent' => null,
                'handoff_brief' => 'Find 10 CTOs needing custom software',
                'handoff_query' => 'CTO custom software',
                'confidence' => 0.9,
            ],
        ]);

        $result = app(SemanticTurnPlanService::class)->interpret(
            $user,
            $org->id,
            '10 is fine',
            $conversation,
        );

        SemanticTurnPlanAgent::assertPrompted(function ($prompt) {
            $payload = json_decode((string) $prompt->prompt, true);

            return ($payload['user_message'] ?? null) === '10 is fine'
                && count($payload['thread'] ?? []) >= 3
                && ($payload['workspace']['icp']['decision_maker'] ?? null) === 'CTOs needing custom software';
        });

        $this->assertSame('find_only', $result['enforcement']['required_outcome'] ?? null);
        $this->assertSame(10, $result['enforcement']['measurable_expectations']['target_count'] ?? null);
        $this->assertSame('CTOs needing custom software', $result['enforcement']['objective']['segment'] ?? null);
    }

    /**
     * @return array{0:User,1:V2Organization,2:AiConversation}
     */
    private function seedThread(): array
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Thread Org',
            'slug' => 'thread-org-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $user->forceFill(['current_organization_id' => $org->id])->save();

        AiEmployeeSetting::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'enabled' => true,
            'autonomy_level' => 2,
            'meta' => [
                'stored_icp' => [
                    'icp' => [
                        'decision_maker' => 'CTOs needing custom software',
                        'industry' => 'Software',
                        'likely_pain' => 'Manual operations',
                    ],
                ],
            ],
        ]);

        $conversation = AiConversation::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'channel' => 'web',
            'status' => 'active',
        ]);

        foreach ([
            ['user', 'i just want to work with instagram email linkedin and whatsapp'],
            ['assistant', 'Got it — I’ll focus your outreach on Instagram, Email, LinkedIn, and WhatsApp only.'],
            ['user', 'get me my client then'],
            ['assistant', 'Your best-fit clients are CTOs and business owners. How many prospects do you want?'],
        ] as [$role, $content]) {
            AiMessage::query()->create([
                'conversation_id' => $conversation->id,
                'role' => $role,
                'content' => $content,
            ]);
        }

        return [$user->fresh(), $org, $conversation];
    }
}

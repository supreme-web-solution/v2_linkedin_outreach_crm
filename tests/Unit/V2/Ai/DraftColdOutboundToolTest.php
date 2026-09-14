<?php

namespace Tests\Unit\V2\Ai;

use App\Ai\Tools\DraftColdOutboundTool;
use App\Models\AiActionApproval;
use App\Models\AiConversation;
use App\Models\AiEmployeeSetting;
use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Ai\AgentContext;
use App\V2\Ai\Services\OneShotOutboundCommandCenterService;
use App\V2\Ai\Services\TurnPlanContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use Mockery;
use Tests\TestCase;

class DraftColdOutboundToolTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        app(TurnPlanContext::class)->clear();
        parent::tearDown();
    }

    public function test_tool_stages_via_one_shot_command_center(): void
    {
        [$user, $org, $conversation, $settings] = $this->seedContext();

        $approval = AiActionApproval::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'conversation_id' => $conversation->id,
            'tool' => 'draft_campaign_plan',
            'permission' => 'prepare',
            'status' => 'pending',
            'payload' => ['source' => 'cold_outbound', 'one_shot' => true],
        ]);

        $oneShot = Mockery::mock(OneShotOutboundCommandCenterService::class);
        $oneShot->shouldReceive('stage')
            ->once()
            ->withArgs(function ($u, $orgId, $conv, $message, $identity) use ($user, $org, $conversation) {
                return $u->is($user)
                    && $orgId === $org->id
                    && $conv->is($conversation)
                    && str_contains($message, 'hello@acme.test')
                    && ($identity['channel'] ?? null) === 'email'
                    && ($identity['email'] ?? null) === 'hello@acme.test';
            })
            ->andReturn([
                'handled' => true,
                'reply' => 'Cold email staged for review.',
                'approval' => $approval,
            ]);
        $this->app->instance(OneShotOutboundCommandCenterService::class, $oneShot);

        app(TurnPlanContext::class)->set([
            'required_outcome' => 'send_now',
            'side_effect_budget' => 'external_send_allowed',
            'constraints' => ['cold_one_shot' => true],
        ]);

        $context = new AgentContext(
            user: $user,
            organizationId: $org->id,
            settings: $settings,
            conversation: $conversation,
            channel: 'web',
        );

        $tool = new DraftColdOutboundTool($context);
        $raw = $tool->handle(new Request([
            'owner_message' => 'Send a researched email to hello@acme.test',
            'email' => 'hello@acme.test',
            'channel' => 'email',
        ]));

        $payload = json_decode((string) $raw, true);
        $this->assertTrue($payload['ok'] ?? false);
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $this->assertSame($approval->id, $data['approval_id'] ?? null);
        $this->assertSame('email', $data['channel'] ?? null);
    }

    /**
     * @return array{0:User,1:V2Organization,2:AiConversation,3:AiEmployeeSetting}
     */
    private function seedContext(): array
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Cold Tool Org',
            'slug' => 'cold-tool-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $user->forceFill(['current_organization_id' => $org->id])->save();

        $settings = AiEmployeeSetting::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'enabled' => true,
            'autonomy_level' => 2,
        ]);

        $conversation = AiConversation::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'channel' => 'web',
            'status' => 'active',
        ]);

        return [$user->fresh(), $org, $conversation, $settings];
    }
}

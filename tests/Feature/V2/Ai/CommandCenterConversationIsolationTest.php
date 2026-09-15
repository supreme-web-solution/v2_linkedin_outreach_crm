<?php

namespace Tests\Feature\V2\Ai;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Ai\Services\CommandCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommandCenterConversationIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_user_gets_their_own_command_center_conversation(): void
    {
        [$userA, $orgA] = $this->userWithOrg('a');
        [$userB, $orgB] = $this->userWithOrg('b');

        $cc = app(CommandCenterService::class);
        $convA = $cc->conversation($userA, $orgA->id);
        AiMessage::query()->create([
            'conversation_id' => $convA->id,
            'role' => 'assistant',
            'content' => 'Secret thread for user A about Bryan Menegussi',
            'meta' => ['channel' => 'web'],
        ]);

        $convB = $cc->conversation($userB, $orgB->id);
        $this->assertNotSame($convA->id, $convB->id);
        $this->assertSame($userA->id, (int) $convA->user_id);
        $this->assertSame($userB->id, (int) $convB->user_id);

        $this->actingAs($userB);
        $response = $this->get('/ai-employee');
        $response->assertOk();
        $page = $response->original->getData()['page']['props'] ?? null;
        if (is_array($page)) {
            $messages = $page['messages'] ?? [];
            $blob = json_encode($messages);
            $this->assertStringNotContainsString('Bryan Menegussi', (string) $blob);
            $this->assertSame($convB->id, (int) ($page['conversation_id'] ?? 0));
        }
    }

    public function test_widget_bootstrap_returns_owner_ids_for_session_binding(): void
    {
        [$user, $org] = $this->userWithOrg('widget');
        $this->actingAs($user);

        $response = $this->getJson('/ai-employee/widget/bootstrap');
        $response->assertOk();
        $response->assertJsonPath('owner_user_id', $user->id);
        $response->assertJsonPath('owner_organization_id', $org->id);
    }

    /**
     * @return array{0:User,1:V2Organization}
     */
    private function userWithOrg(string $suffix): array
    {
        $user = User::factory()->create([
            'email' => "owner-{$suffix}-".uniqid().'@example.com',
        ]);
        $org = V2Organization::query()->create([
            'name' => 'Org '.$suffix,
            'slug' => 'org-'.$suffix.'-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $user->forceFill(['current_organization_id' => $org->id])->save();

        return [$user->fresh(), $org];
    }
}

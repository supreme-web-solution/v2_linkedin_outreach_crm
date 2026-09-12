<?php

namespace Tests\Unit\V2\Ai;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiWorkflowRun;
use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Ai\Services\WorkflowConversationNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WorkflowConversationNotifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_find_only_completion_includes_sample_links_and_mirrors_whatsapp(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        config()->set('socifusion_ai.zernio.api_key', 'test-key');
        config()->set('socifusion_ai.zernio.account_id', 'acc-1');
        config()->set('socifusion_ai.command_center_mirror_whatsapp', true);

        [$user, $org, $conversation] = $this->fixtures();

        \App\Models\AiChannelIdentity::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'channel' => 'whatsapp',
            'external_id' => '+15551234567',
            'status' => 'active',
            'meta' => [
                'zernio_conversation_id' => 'conv-1',
                'zernio_account_id' => 'acc-1',
            ],
        ]);

        $run = AiWorkflowRun::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'conversation_id' => $conversation->id,
            'status' => 'completed',
            'plan' => ['required_outcome' => 'find_only'],
            'result' => ['requested' => 50, 'discovered_this_run' => 50],
            'meta' => [
                'platforms_searched' => ['instagram'],
                'discovery_list_hash' => 'ig-list-abc',
                'sample_profiles' => [[
                    'name' => 'Coffee Brand',
                    'username' => 'coffeebrand',
                    'headline' => 'Artisan roasters',
                    'profile_url' => 'https://www.instagram.com/coffeebrand',
                    'platform' => 'instagram',
                ]],
            ],
        ]);

        app(WorkflowConversationNotifier::class)->notifyCompleted($run);

        $message = AiMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->latest('id')
            ->first();

        $this->assertNotNull($message);
        $this->assertStringContainsString('finished discovery', (string) $message->content);
        $this->assertStringContainsString('instagram.com/coffeebrand', (string) $message->content);
        $this->assertStringContainsString('/leads?list=ig-list-abc', (string) $message->content);
        $this->assertSame('workflow_runtime', $message->meta['source'] ?? null);

        Http::assertSentCount(1);
    }

    /**
     * @return array{0: User, 1: V2Organization, 2: AiConversation}
     */
    private function fixtures(): array
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Workflow Notify Org',
            'slug' => 'wf-notify-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $user->forceFill(['current_organization_id' => $org->id])->save();

        $conversation = AiConversation::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'channel' => 'command_center',
            'status' => 'open',
            'title' => 'Command Center',
        ]);

        return [$user, $org, $conversation];
    }
}

<?php

namespace Tests\Unit\V2\Ai;

use App\Models\AiConversation;
use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Ai\AgentContext;
use App\V2\Ai\Services\WebChatTurnProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebChatTurnProgressServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_relays_sales_brief_as_final_web_update(): void
    {
        [$context] = $this->webContext();
        $progress = app(WebChatTurnProgressService::class);
        $progress->bind($context);

        $progress->relayToolComplete('get_sales_brief', [
            'brief' => 'Today: 12 leads, 3 replies, 1 campaign live.',
        ], 120);

        $this->assertTrue($progress->postedFinalReply());

        $message = AiConversation::query()->find($context->conversation->id)
            ?->messages()
            ->where('role', 'assistant')
            ->latest('id')
            ->first();

        $this->assertNotNull($message);
        $this->assertSame('Today: 12 leads, 3 replies, 1 campaign live.', $message->content);
        $this->assertTrue((bool) ($message->meta['progress'] ?? true) === false);
    }

    public function test_skips_duplicate_discover_relay_when_streamed(): void
    {
        [$context] = $this->webContext();
        $progress = app(WebChatTurnProgressService::class);
        $progress->bind($context);

        $progress->afterDiscoveryChannel(
            channel: 'linkedin',
            result: [
                'best_match' => ['list_name' => 'Owners', 'total_leads' => 3],
                'sample_profiles' => [['name' => 'Ada', 'headline' => 'Founder']],
            ],
            moreChannelsPending: false,
            nextLabel: null,
            allChannelResults: [
                'linkedin' => [
                    'best_match' => ['list_name' => 'Owners', 'total_leads' => 3],
                    'sample_profiles' => [['name' => 'Ada', 'headline' => 'Founder']],
                ],
            ],
        );

        $countBefore = $context->conversation->messages()->where('role', 'assistant')->count();

        $progress->relayToolComplete('discover_prospects', [
            'execution_report' => 'Saved 3 prospects.',
            'discovery_only' => true,
        ], 5000);

        $this->assertSame($countBefore, $context->conversation->messages()->where('role', 'assistant')->count());
    }

    /**
     * @return array{0: AgentContext}
     */
    private function webContext(): array
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Progress Org',
            'slug' => 'progress-org-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $conversation = AiConversation::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'channel' => 'command_center',
            'status' => 'open',
            'title' => 'Command Center',
        ]);

        $settings = app(\App\V2\Ai\Services\AiEmployeeSettingsService::class)->for($user, $org->id);

        return [new AgentContext(
            user: $user,
            organizationId: $org->id,
            settings: $settings,
            conversation: $conversation,
            channel: 'web',
        )];
    }
}

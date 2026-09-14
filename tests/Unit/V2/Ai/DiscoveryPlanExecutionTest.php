<?php

namespace Tests\Unit\V2\Ai;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiWorkflowRun;
use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Ai\Services\PlanChannelIntentService;
use App\V2\Ai\Services\WorkflowConversationNotifier;
use App\V2\Ai\Services\WorkflowDiscoveryStepHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiscoveryPlanExecutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_instagram_keyword_compresses_meeting_ask_to_buyer_niche(): void
    {
        $intent = app(PlanChannelIntentService::class);

        $keyword = $intent->instagramKeyword([
            'audience' => 'Book 20 meetings with US SaaS founders',
            'goal' => 'Book 20 meetings with US SaaS founders',
        ], [
            'who_we_sell_to' => 'long ICP essay about digital product businesses that need tailored software automation and growing application platforms across many industries',
        ]);

        $lower = strtolower($keyword);
        $this->assertStringContainsString('saas', $lower);
        $this->assertStringContainsString('founder', $lower);
        $this->assertStringNotContainsString('book', $lower);
        $this->assertStringNotContainsString('meeting', $lower);
        $this->assertLessThan(90, strlen($keyword));
    }

    public function test_parallel_step_does_not_count_reused_lists_toward_provider_returned(): void
    {
        $handler = app(WorkflowDiscoveryStepHandler::class);
        $method = new \ReflectionMethod($handler, 'buildParallelStepResult');
        $method->setAccessible(true);

        $result = $method->invoke($handler, [
            'mode' => 'parallel',
            'total_leads_in_matches' => 0,
            'platforms_searched' => [],
            'platform_failures' => ['Instagram discovery failed: timeout'],
            'channel_results' => [
                'instagram' => [
                    'search_failed' => true,
                    'failure_reason' => 'timeout',
                    'lists' => [],
                ],
                'linkedin' => [
                    'best_match' => [
                        'list_hash' => 'li-old',
                        'list_src' => 'sn',
                        'list_name' => 'Prior LI',
                        'total_leads' => 10,
                        'auto_sourced' => true,
                        'reused_recent' => true,
                    ],
                    'lists' => [[
                        'list_hash' => 'li-old',
                        'list_src' => 'sn',
                        'list_name' => 'Prior LI',
                        'total_leads' => 10,
                        'auto_sourced' => true,
                        'reused_recent' => true,
                    ]],
                ],
            ],
        ], 20, 'all');

        // Reused prior lists must not count as new discovery progress.
        $this->assertSame(0, $result['provider_returned']);
        $this->assertSame(0, $result['saved_reported']);
        $this->assertNotEmpty($result['platform_failures']);
        $this->assertTrue($result['discovery_result']['search_failed']);
    }

    public function test_parallel_step_caps_provider_returned_at_requested_and_keeps_spill_lists(): void
    {
        $handler = app(WorkflowDiscoveryStepHandler::class);
        $method = new \ReflectionMethod($handler, 'buildParallelStepResult');
        $method->setAccessible(true);

        $result = $method->invoke($handler, [
            'mode' => 'parallel',
            'total_leads_in_matches' => 62,
            'platforms_searched' => ['instagram', 'linkedin'],
            'channel_results' => [
                'instagram' => [
                    'best_match' => [
                        'list_hash' => 'ig-1',
                        'total_leads' => 6,
                        'auto_sourced' => true,
                    ],
                    'lists' => [[
                        'list_hash' => 'ig-1',
                        'total_leads' => 6,
                        'auto_sourced' => true,
                    ]],
                ],
                'linkedin' => [
                    'best_match' => [
                        'list_hash' => 'li-spill',
                        'total_leads' => 24,
                        'auto_sourced' => true,
                        'spill_merged' => true,
                    ],
                    'lists' => [
                        [
                            'list_hash' => 'li-1',
                            'total_leads' => 15,
                            'auto_sourced' => true,
                        ],
                        [
                            'list_hash' => 'li-spill',
                            'total_leads' => 9,
                            'auto_sourced' => true,
                        ],
                        // Prior matched lists must not inflate progress when spill_merged.
                        [
                            'list_hash' => 'li-old',
                            'total_leads' => 32,
                            'auto_sourced' => true,
                            'reused_recent' => true,
                        ],
                    ],
                ],
            ],
        ], 30, 'all');

        $this->assertSame(30, $result['provider_returned']);
        $hashes = collect($result['discovery_lists'])->pluck('list_hash')->all();
        $this->assertContains('ig-1', $hashes);
        $this->assertContains('li-1', $hashes);
        $this->assertContains('li-spill', $hashes);
        $this->assertNotContains('li-old', $hashes);
    }

    public function test_compress_strips_trailing_and(): void
    {
        $keyword = app(PlanChannelIntentService::class)->compressBuyerKeyword(
            'united kingdom saas founders and'
        );

        $this->assertSame('united kingdom saas founders', strtolower($keyword));
    }

    public function test_discovery_notifier_reports_shortfall_honestly(): void
    {
        [$user, $org, $conversation] = $this->fixtures();

        $run = AiWorkflowRun::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'conversation_id' => $conversation->id,
            'status' => 'running',
            'plan' => [
                'required_outcome' => 'setup_only',
                'constraints' => ['target_count' => 20],
            ],
            'result' => [],
            'meta' => [
                'cumulative_candidate_delta' => 10,
                'platforms_searched' => ['linkedin'],
                'platform_failures' => ['Instagram discovery failed: timeout'],
            ],
        ]);

        app(WorkflowConversationNotifier::class)->notifyDiscoveryComplete($run);

        $message = AiMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->latest('id')
            ->first();

        $this->assertNotNull($message);
        $this->assertStringContainsString('found 10 of 20', (string) $message->content);
        $this->assertStringContainsString('short of target', (string) $message->content);
        $this->assertStringContainsString('linkedin', strtolower((string) $message->content));
        $this->assertStringContainsString('Instagram discovery failed', (string) $message->content);
        $this->assertStringNotContainsString('instagram + linkedin', strtolower((string) $message->content));
    }

    /**
     * @return array{0: User, 1: V2Organization, 2: AiConversation}
     */
    private function fixtures(): array
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Discovery Exec Org',
            'slug' => 'disc-exec-'.uniqid(),
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

<?php

namespace Tests\Unit\V2\Ai;

use App\Models\AiActionLog;
use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\Models\V2OutreachCampaign;
use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\AiActionHistoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiActionHistoryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_prepare_linkedin_post_in_activity(): void
    {
        [$user, $org] = $this->userWithOrg();

        AiActionLog::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'tool' => 'prepare_linkedin_post',
            'permission' => AiToolPermission::Prepare->value,
            'status' => 'success',
            'input' => ['args' => ['topic' => 'AI affiliate marketing']],
            'output' => [
                'auto_scheduled' => true,
                'message' => 'Scheduled for Friday 10am',
                'plan' => [
                    'post_id' => 42,
                    'topic' => 'AI affiliate marketing',
                    'scheduled_label' => 'Fri Sep 11, 10:00 AM',
                ],
            ],
        ]);

        $items = app(AiActionHistoryService::class)->recent($user, $org->id, 5);
        $item = $items[0] ?? null;

        $this->assertNotNull($item);
        $this->assertSame('prepare_linkedin_post', $item['tool']);
        $this->assertSame('LinkedIn post', $item['label']);
        $this->assertTrue((bool) ($item['can_undo'] ?? false));
        $this->assertStringContainsString('Scheduled', (string) ($item['summary'] ?? ''));
    }

    public function test_lists_execute_actions_and_marks_reversible(): void
    {
        [$user, $org] = $this->userWithOrg();

        $pause = AiActionLog::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'tool' => 'pause_outreach_campaign',
            'permission' => AiToolPermission::Execute->value,
            'status' => 'success',
            'input' => ['args' => []],
            'output' => [
                'ok' => true,
                'message' => 'Paused 1 campaign',
                'paused' => [99],
                'previous_statuses' => [99 => 'running'],
            ],
        ]);

        AiActionLog::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'tool' => 'send_inbox_reply',
            'permission' => AiToolPermission::Execute->value,
            'status' => 'success',
            'input' => ['args' => ['conversation_id' => 1]],
            'output' => ['message' => 'Sent'],
        ]);

        $items = app(AiActionHistoryService::class)->recent($user, $org->id, 10);

        $this->assertCount(2, $items);
        $pauseItem = collect($items)->firstWhere('id', $pause->id);
        $sendItem = collect($items)->firstWhere('tool', 'send_inbox_reply');

        $this->assertTrue((bool) ($pauseItem['can_undo'] ?? false));
        $this->assertFalse((bool) ($sendItem['can_undo'] ?? true));
        $this->assertStringContainsString('cannot be unsent', (string) ($sendItem['undo_hint'] ?? ''));
    }

    public function test_paginate_returns_page_meta(): void
    {
        [$user, $org] = $this->userWithOrg();

        for ($i = 0; $i < 3; $i++) {
            AiActionLog::query()->create([
                'organization_id' => $org->id,
                'user_id' => $user->id,
                'tool' => 'pause_outreach_campaign',
                'permission' => AiToolPermission::Execute->value,
                'status' => 'success',
                'input' => ['args' => []],
                'output' => ['message' => "Paused {$i}", 'paused' => []],
            ]);
        }

        $page = app(AiActionHistoryService::class)->paginate($user, $org->id, 2);

        $this->assertCount(2, $page['data']);
        $this->assertSame(3, $page['total']);
        $this->assertSame(2, $page['last_page']);
        $this->assertSame(1, $page['current_page']);
    }

    public function test_undo_pause_restores_campaign_status(): void
    {
        [$user, $org] = $this->userWithOrg();

        $campaign = V2OutreachCampaign::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'name' => 'Test pause undo',
            'status' => 'paused',
            'node_model' => [],
        ]);

        $log = AiActionLog::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'tool' => 'pause_outreach_campaign',
            'permission' => AiToolPermission::Execute->value,
            'status' => 'success',
            'input' => ['args' => ['campaign_id' => $campaign->id]],
            'output' => [
                'ok' => true,
                'message' => 'Paused',
                'paused' => [$campaign->id],
                'previous_statuses' => [$campaign->id => 'running'],
            ],
        ]);

        $result = app(AiActionHistoryService::class)->undo($user, $org->id, $log->id);

        $this->assertTrue($result['ok']);
        $this->assertSame('running', $campaign->fresh()->status);
        $this->assertNotNull($log->fresh()->undone_at);

        $second = app(AiActionHistoryService::class)->undo($user, $org->id, $log->id);
        $this->assertFalse($second['ok']);
    }

    /**
     * @return array{0:User, 1:V2Organization}
     */
    private function userWithOrg(): array
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Test Org',
            'slug' => 'test-org-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        $user->forceFill(['current_organization_id' => $org->id])->save();
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        return [$user, $org];
    }
}

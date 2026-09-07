<?php

namespace Tests\Unit\V2\Ai;

use App\Models\AiActionApproval;
use App\Models\User;
use App\Models\V2ContentPost;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Ai\Services\CommandCenterService;
use App\V2\Ai\Services\ContentPostCommandCenterService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ContentPostCommandCenterServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduled_post_does_not_require_linkedin_on_launch(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-07 15:00:00', 'UTC'));
        Queue::fake();

        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Content Org',
            'slug' => 'content-org-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $post = V2ContentPost::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'linkedin',
            'content' => 'Sales and marketing alignment post',
            'status' => 'draft',
            'meta' => ['post_type' => 'text'],
        ]);

        $scheduledAt = Carbon::parse('2026-09-08 10:00:00', 'UTC')->toIso8601String();

        $approval = AiActionApproval::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'tool' => 'prepare_linkedin_post',
            'permission' => 'prepare',
            'status' => 'pending',
            'payload' => [
                'type' => 'linkedin_post',
                'post_id' => $post->id,
                'scheduled_at' => $scheduledAt,
            ],
        ]);

        $block = (new \ReflectionClass(CommandCenterService::class))
            ->getMethod('blockedLaunchMissingIntegrations');
        $block->setAccessible(true);
        $integrationBlock = $block->invoke(app(CommandCenterService::class), $approval, $user);

        $this->assertNull($integrationBlock);

        $result = app(ContentPostCommandCenterService::class)->publishFromApproval($approval, $user);

        $this->assertSame('scheduled', $post->fresh()->status);
        $this->assertStringContainsString('Scheduled for LinkedIn', $result['message']);

        Carbon::setTestNow();
    }
}

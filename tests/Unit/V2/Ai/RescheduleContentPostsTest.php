<?php

namespace Tests\Unit\V2\Ai;

use App\Models\User;
use App\Models\V2ContentPost;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Ai\Enums\AiAutonomyLevel;
use App\V2\Ai\Services\ContentPostCommandCenterService;
use App\V2\Ai\Support\ScheduleAtParser;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RescheduleContentPostsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reschedules_posts_from_tomorrow_to_wednesday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-07 15:00:00', 'UTC'));
        Queue::fake();

        [$user, $org, $conversation] = $this->fixtures();

        $tomorrow = Carbon::parse('2026-09-08 10:00:00', 'UTC');
        $post = V2ContentPost::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'linkedin',
            'content' => 'Sales alignment post',
            'status' => 'scheduled',
            'scheduled_at' => $tomorrow,
            'meta' => ['post_type' => 'text'],
        ]);

        $result = app(ContentPostCommandCenterService::class)->reschedulePosts(
            user: $user,
            organizationId: $org->id,
            conversation: $conversation,
            toScheduleAt: 'Wednesday',
            fromScheduledDay: 'tomorrow',
            postIds: null,
            autonomy: AiAutonomyLevel::Autonomous,
            surface: 'whatsapp',
        );

        $this->assertSame(1, $result['rescheduled']);
        $fresh = $post->fresh();
        $this->assertSame('scheduled', $fresh->status);
        $this->assertSame('Wednesday', $fresh->scheduled_at?->format('l'));
        $this->assertSame(10, (int) $fresh->scheduled_at?->format('G'));

        Carbon::setTestNow();
    }

    public function test_day_range_for_tomorrow(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-07 15:00:00', 'UTC'));
        $range = ScheduleAtParser::dayRange('tomorrow', 'UTC');
        $this->assertNotNull($range);
        $this->assertSame('2026-09-08', $range['start']->format('Y-m-d'));
        Carbon::setTestNow();
    }

    /**
     * @return array{0:User,1:V2Organization,2:\App\Models\AiConversation}
     */
    private function fixtures(): array
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Reschedule Org',
            'slug' => 'reschedule-org-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $conversation = app(\App\V2\Ai\Services\CommandCenterService::class)->conversation($user, $org->id);

        return [$user, $org, $conversation];
    }
}

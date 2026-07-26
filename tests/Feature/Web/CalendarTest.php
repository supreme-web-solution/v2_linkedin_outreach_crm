<?php

namespace Tests\Feature\Web;

use App\Models\User;
use App\Models\V2Call;
use App\Models\V2ContentPost;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CalendarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('billing.require_entitlement', false);
    }

    public function test_calendar_page_lists_scheduled_events(): void
    {
        $user = $this->userWithOrg();
        $month = now()->format('Y-m');
        $callAt = now()->addDays(3)->setTime(14, 0);

        V2Call::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'prospect_name' => 'Alex Prospect',
            'status' => 'booked',
            'scheduled_call_at' => $callAt,
        ]);

        V2ContentPost::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'provider' => 'linkedin',
            'content' => 'Scheduled LinkedIn post body',
            'status' => 'scheduled',
            'scheduled_at' => now()->addDays(5)->setTime(10, 0),
        ]);

        $response = $this->actingAs($user)->get(route('calendar', ['month' => $month]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('crm/Calendar/Index')
            ->where('month', $month)
            ->where('hasOrg', true)
            ->has('events', 2)
        );
    }

    public function test_reschedule_call_updates_scheduled_time(): void
    {
        $user = $this->userWithOrg();
        $call = V2Call::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'prospect_name' => 'Jordan Lee',
            'status' => 'booked',
            'scheduled_call_at' => now()->addDays(2)->setTime(15, 30),
        ]);

        $newStart = now()->addDays(7)->setTime(15, 30);

        $response = $this->actingAs($user)->patchJson(route('calendar.events.reschedule', [
            'type' => 'call',
            'id' => $call->id,
        ]), [
            'start' => $newStart->toIso8601String(),
        ]);

        $response->assertOk();
        $response->assertJsonPath('event.type', 'call');

        $call->refresh();
        $this->assertTrue($call->scheduled_call_at->equalTo($newStart));
        $this->assertSame('booked', $call->status);
    }

    public function test_reschedule_content_post_updates_scheduled_at(): void
    {
        Queue::fake();

        $user = $this->userWithOrg();
        $post = V2ContentPost::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'provider' => 'linkedin',
            'content' => 'Post to move',
            'status' => 'scheduled',
            'scheduled_at' => now()->addDay()->setTime(9, 0),
        ]);

        $newStart = now()->addDays(4)->setTime(9, 0);

        $response = $this->actingAs($user)->patchJson(route('calendar.events.reschedule', [
            'type' => 'content',
            'id' => $post->id,
        ]), [
            'start' => $newStart->toIso8601String(),
        ]);

        $response->assertOk();
        $response->assertJsonPath('event.type', 'content');

        $post->refresh();
        $this->assertTrue($post->scheduled_at->equalTo($newStart));
        $this->assertSame('scheduled', $post->status);
    }

    public function test_reschedule_rejects_past_datetime(): void
    {
        $user = $this->userWithOrg();
        $call = V2Call::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'prospect_name' => 'Past Test',
            'status' => 'booked',
            'scheduled_call_at' => now()->addDay(),
        ]);

        $response = $this->actingAs($user)->patchJson(route('calendar.events.reschedule', [
            'type' => 'call',
            'id' => $call->id,
        ]), [
            'start' => now()->subHour()->toIso8601String(),
        ]);

        $response->assertStatus(422);
    }

    private function userWithOrg(): User
    {
        $user = User::factory()->create();
        $organization = V2Organization::query()->create([
            'name' => 'Calendar Org',
            'slug' => 'calendar-org-'.$user->id,
            'status' => 'active',
        ]);

        V2OrganizationUser::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'capabilities' => ['*'],
            'status' => 'active',
        ]);

        $user->forceFill(['current_organization_id' => $organization->id])->save();

        return $user->fresh();
    }
}

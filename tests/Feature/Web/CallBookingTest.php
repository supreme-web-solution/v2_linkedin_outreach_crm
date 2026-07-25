<?php

namespace Tests\Feature\Web;

use App\Models\User;
use App\Models\V2Call;
use App\Models\V2IntegrationAccount;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\Models\V2UserActivity;
use App\Mail\CallBookedOwnerMail;
use App\V2\Integrations\Unipile\UnipileProvider;
use App\V2\Services\CallCalendarService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class CallBookingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('billing.require_entitlement', false);
    }

    public function test_public_booking_page_lists_slots_and_books_call(): void
    {
        Mail::fake();

        $user = $this->userWithOrg();
        $user->forceFill([
            'call_settings' => [
                'use_app_booking_link' => true,
                'use_unipile_calendar' => false,
                'call_duration_minutes' => 30,
                'booking_hours_start' => 9,
                'booking_hours_end' => 17,
            ],
        ])->save();

        V2IntegrationAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'google_calendar',
            'provider_account_id' => 'uni_cal',
            'status' => 'active',
            'meta' => ['unipile_account_id' => 'uni_cal'],
        ]);

        $token = 'test-booking-token-abc123';
        $call = V2Call::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'prospect_name' => 'Jane Doe',
            'status' => 'scheduling',
            'meta' => ['booking_token' => $token],
        ]);

        $unipile = Mockery::mock(UnipileProvider::class);
        $unipile->shouldReceive('listCalendars')->andReturn(['items' => [['id' => 'cal1', 'name' => 'Primary', 'primary' => true]]]);
        $unipile->shouldReceive('listCalendarEvents')->andReturn(['items' => []]);
        $this->app->instance(UnipileProvider::class, $unipile);

        $slotStart = Carbon::now()->addDays(2)->setTime(10, 0)->startOfHour();
        while ($slotStart->isWeekend()) {
            $slotStart->addDay();
        }

        $this->get(route('book.show', ['token' => $token]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('public/BookCall')
                ->where('prospectName', 'Jane Doe')
                ->has('slots')
            );

        $calendar = app(CallCalendarService::class);
        $slots = $calendar->availableSlotsForUser($user->fresh());
        $this->assertNotEmpty($slots);

        $calendar->bookCallAt(
            $call->fresh(),
            $user->fresh(),
            Carbon::parse($slots[0]['start']),
            'public_booking',
            'jane@example.com',
        );

        $call->refresh();
        $this->assertSame('booked', $call->status);
        $this->assertNotNull($call->scheduled_call_at);
        $this->assertSame('jane@example.com', $call->meta['prospect_email']);

        $this->assertDatabaseHas('v2_user_activities', [
            'user_id' => $user->id,
            'module' => 'calls',
            'identifier' => 'Jane Doe booked a call with you',
        ]);

        Mail::assertSent(CallBookedOwnerMail::class, fn (CallBookedOwnerMail $mail) => $mail->hasTo($user->email));
    }

    public function test_public_booking_requires_prospect_email(): void
    {
        $user = $this->userWithOrg();
        V2IntegrationAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'google_calendar',
            'provider_account_id' => 'uni_cal',
            'status' => 'active',
            'meta' => ['unipile_account_id' => 'uni_cal'],
        ]);

        $token = 'test-booking-token-email-required';
        V2Call::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'prospect_name' => 'Jane Doe',
            'status' => 'scheduling',
            'meta' => ['booking_token' => $token],
        ]);

        $this->post(route('book.store', ['token' => $token]), [
            'slot_start' => now()->addDays(3)->toIso8601String(),
        ])->assertSessionHasErrors('prospect_email');

        $this->assertSame(0, V2UserActivity::query()->where('module', 'calls')->count());
    }

    public function test_resolve_booking_url_uses_app_link_when_in_app_booking_enabled(): void
    {
        $user = $this->userWithOrg();
        $user->forceFill([
            'call_settings' => [
                'calendar_url' => 'https://calendly.com/me/15',
                'use_app_booking_link' => true,
            ],
        ])->save();

        V2IntegrationAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'google_calendar',
            'provider_account_id' => 'uni_cal',
            'status' => 'active',
            'meta' => ['unipile_account_id' => 'uni_cal'],
        ]);

        $url = app(CallCalendarService::class)->resolveBookingUrl($user, $user->call_settings, 'token123');
        $this->assertSame(url('/book/token123'), $url);
    }

    public function test_resolve_booking_url_uses_manual_link_when_in_app_booking_disabled(): void
    {
        $user = $this->userWithOrg();
        $user->forceFill([
            'call_settings' => [
                'calendar_url' => 'https://calendly.com/me/15',
                'use_app_booking_link' => false,
            ],
        ])->save();

        $url = app(CallCalendarService::class)->resolveBookingUrl($user, $user->call_settings, 'token123');
        $this->assertSame('https://calendly.com/me/15', $url);
    }

    private function userWithOrg(): User
    {
        $user = User::factory()->create();
        $organization = V2Organization::query()->create([
            'name' => 'Booking Org',
            'slug' => 'booking-org-'.$user->id,
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

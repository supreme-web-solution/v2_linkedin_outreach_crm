<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Models\V2Call;
use App\Models\V2IntegrationAccount;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Integrations\Unipile\UnipileProvider;
use App\V2\Services\CallCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CallCalendarServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_sync_event_for_call_creates_provider_event_and_stores_meta(): void
    {
        $user = $this->userWithOrg();
        $user->forceFill([
            'name' => 'Host User',
            'call_settings' => [
                'use_unipile_calendar' => true,
                'call_duration_minutes' => 30,
                'calendar_id' => 'cal_primary',
            ],
        ])->save();

        V2IntegrationAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'google_calendar',
            'provider_account_id' => 'uni_123',
            'status' => 'active',
            'meta' => [
                'unipile_account_id' => 'uni_123',
                'unipile_type' => 'GOOGLE_OAUTH',
            ],
        ]);

        $call = V2Call::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'prospect_name' => 'Jane Doe',
            'prospect_headline' => 'VP Sales',
            'status' => 'booked',
            'scheduled_call_at' => now()->addDays(2)->startOfHour(),
            'meta' => ['prospect_email' => 'jane@example.com'],
        ]);

        $unipile = Mockery::mock(UnipileProvider::class);
        $unipile->shouldReceive('createCalendarEvent')
            ->once()
            ->with('uni_123', 'cal_primary', Mockery::on(function (array $body) {
                return ($body['title'] ?? '') === 'Call with Host User'
                    && is_array($body['start'] ?? null)
                    && is_array($body['end'] ?? null)
                    && isset($body['start']['time_zone'])
                    && str_ends_with((string) ($body['start']['date_time'] ?? ''), 'Z')
                    && ($body['notify'] ?? false) === true
                    && ($body['attendees'][0]['email'] ?? '') === 'jane@example.com'
                    && ($body['conference']['provider'] ?? '') === 'google_meet';
            }))
            ->andReturn([
                'id' => 'evt_999',
                'html_link' => 'https://calendar.google.com/event?eid=evt_999',
                'conference' => ['url' => 'https://meet.google.com/abc-defg-hij'],
            ]);

        $this->app->instance(UnipileProvider::class, $unipile);

        $result = app(CallCalendarService::class)->syncEventForCall($call->fresh(), $user);

        $this->assertSame('evt_999', $result['event_id']);
        $this->assertSame('https://calendar.google.com/event?eid=evt_999', $result['html_link']);
        $this->assertSame('https://meet.google.com/abc-defg-hij', $result['meeting_url']);

        $call->refresh();
        $this->assertSame('evt_999', $call->meta['calendar_event_id']);
        $this->assertSame('https://calendar.google.com/event?eid=evt_999', $call->meta['calendar_html_link']);
        $this->assertSame('https://meet.google.com/abc-defg-hij', $call->meta['meeting_url']);
        $this->assertSame(
            $call->scheduled_call_at->copy()->utc()->format('Y-m-d\TH:i:s.000\Z'),
            $call->meta['calendar_event_start'],
        );
    }

    public function test_refresh_meeting_link_fetches_from_calendar_event(): void
    {
        $user = $this->userWithOrg();
        $user->forceFill([
            'call_settings' => [
                'use_unipile_calendar' => true,
                'calendar_id' => 'cal_primary',
            ],
        ])->save();

        V2IntegrationAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'google_calendar',
            'provider_account_id' => 'uni_123',
            'status' => 'active',
            'meta' => [
                'unipile_account_id' => 'uni_123',
                'unipile_type' => 'GOOGLE_OAUTH',
            ],
        ]);

        $call = V2Call::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'prospect_name' => 'Jane Doe',
            'status' => 'booked',
            'scheduled_call_at' => now()->addDay(),
            'meta' => [
                'calendar_event_id' => 'evt_existing',
                'calendar_id' => 'cal_primary',
            ],
        ]);

        $unipile = Mockery::mock(UnipileProvider::class);
        $unipile->shouldReceive('getCalendarEvent')
            ->once()
            ->with('uni_123', 'cal_primary', 'evt_existing')
            ->andReturn([
                'id' => 'evt_existing',
                'hangoutLink' => 'https://meet.google.com/xyz-uvwx-rst',
            ]);
        $unipile->shouldNotReceive('createCalendarEvent');
        $this->app->instance(UnipileProvider::class, $unipile);

        $url = app(CallCalendarService::class)->refreshMeetingLinkForCall($call->fresh(), $user);

        $this->assertSame('https://meet.google.com/xyz-uvwx-rst', $url);
        $call->refresh();
        $this->assertSame('https://meet.google.com/xyz-uvwx-rst', $call->meta['meeting_url']);
    }

    public function test_sync_event_returns_existing_event_and_refreshes_missing_meeting_url(): void
    {
        $user = $this->userWithOrg();
        $user->forceFill([
            'call_settings' => [
                'use_unipile_calendar' => true,
                'calendar_id' => 'cal_primary',
            ],
        ])->save();

        V2IntegrationAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'google_calendar',
            'provider_account_id' => 'uni_123',
            'status' => 'active',
            'meta' => [
                'unipile_account_id' => 'uni_123',
                'unipile_type' => 'GOOGLE_OAUTH',
            ],
        ]);

        $scheduledAt = now()->addDay()->startOfHour();
        $call = V2Call::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'prospect_name' => 'Jane Doe',
            'status' => 'booked',
            'scheduled_call_at' => $scheduledAt,
            'meta' => [
                'calendar_event_id' => 'evt_existing',
                'calendar_id' => 'cal_primary',
                'calendar_html_link' => 'https://calendar.google.com/event?eid=evt_existing',
                'calendar_event_start' => $scheduledAt->copy()->utc()->format('Y-m-d\TH:i:s.000\Z'),
            ],
        ]);

        $unipile = Mockery::mock(UnipileProvider::class);
        $unipile->shouldReceive('getCalendarEvent')
            ->once()
            ->with('uni_123', 'cal_primary', 'evt_existing')
            ->andReturn([
                'conference' => ['url' => 'https://meet.google.com/refreshed-link'],
            ]);
        $unipile->shouldNotReceive('createCalendarEvent');
        $this->app->instance(UnipileProvider::class, $unipile);

        $result = app(CallCalendarService::class)->syncEventForCall($call->fresh(), $user);

        $this->assertSame('evt_existing', $result['event_id']);
        $this->assertSame('https://meet.google.com/refreshed-link', $result['meeting_url']);
        $call->refresh();
        $this->assertSame('https://meet.google.com/refreshed-link', $call->meta['meeting_url']);
    }

    public function test_sync_event_updates_existing_calendar_event_when_rescheduled(): void
    {
        $user = $this->userWithOrg();
        $user->forceFill([
            'name' => 'Host User',
            'call_settings' => [
                'use_unipile_calendar' => true,
                'call_duration_minutes' => 30,
                'calendar_id' => 'cal_primary',
            ],
        ])->save();

        V2IntegrationAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'google_calendar',
            'provider_account_id' => 'uni_123',
            'status' => 'active',
            'meta' => [
                'unipile_account_id' => 'uni_123',
                'unipile_type' => 'GOOGLE_OAUTH',
            ],
        ]);

        $originalStart = now()->addDays(2)->startOfHour();
        $newStart = $originalStart->copy()->addHours(2);

        $call = V2Call::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'prospect_name' => 'Jane Doe',
            'status' => 'booked',
            'scheduled_call_at' => $newStart,
            'meta' => [
                'calendar_event_id' => 'evt_existing',
                'calendar_id' => 'cal_primary',
                'calendar_html_link' => 'https://calendar.google.com/event?eid=evt_existing',
                'meeting_url' => 'https://meet.google.com/abc-defg-hij',
                'calendar_event_start' => $originalStart->copy()->utc()->format('Y-m-d\TH:i:s.000\Z'),
                'prospect_email' => 'jane@example.com',
            ],
        ]);

        $unipile = Mockery::mock(UnipileProvider::class);
        $unipile->shouldReceive('updateCalendarEvent')
            ->once()
            ->with('uni_123', 'cal_primary', 'evt_existing', Mockery::on(function (array $body) use ($newStart) {
                return ($body['notify'] ?? false) === true
                    && ($body['start']['date_time'] ?? '') === $newStart->copy()->utc()->format('Y-m-d\TH:i:s.000\Z')
                    && ($body['attendees'][0]['email'] ?? '') === 'jane@example.com';
            }))
            ->andReturn([
                'id' => 'evt_existing',
                'conference' => ['url' => 'https://meet.google.com/abc-defg-hij'],
            ]);
        $unipile->shouldNotReceive('createCalendarEvent');
        $this->app->instance(UnipileProvider::class, $unipile);

        $result = app(CallCalendarService::class)->syncEventForCall($call->fresh(), $user);

        $this->assertSame('evt_existing', $result['event_id']);
        $this->assertSame('https://meet.google.com/abc-defg-hij', $result['meeting_url']);

        $call->refresh();
        $this->assertSame(
            $newStart->copy()->utc()->format('Y-m-d\TH:i:s.000\Z'),
            $call->meta['calendar_event_start'],
        );
    }

    public function test_sync_event_skipped_when_calendar_integration_missing(): void
    {
        $user = $this->userWithOrg();

        $call = V2Call::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'prospect_name' => 'Jane Doe',
            'status' => 'booked',
            'scheduled_call_at' => now()->addDay(),
        ]);

        $unipile = Mockery::mock(UnipileProvider::class);
        $unipile->shouldNotReceive('createCalendarEvent');
        $this->app->instance(UnipileProvider::class, $unipile);

        $result = app(CallCalendarService::class)->syncEventForCall($call, $user);

        $this->assertNull($result);
    }

    public function test_sync_event_uses_email_fallback_when_list_calendars_fails(): void
    {
        $user = $this->userWithOrg();
        $user->forceFill([
            'call_settings' => [
                'use_unipile_calendar' => true,
                'call_duration_minutes' => 30,
            ],
        ])->save();

        V2IntegrationAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'google_calendar',
            'provider_account_id' => 'uni_123',
            'status' => 'active',
            'meta' => [
                'unipile_account_id' => 'uni_123',
                'unipile_type' => 'GOOGLE',
                'email' => 'owner@gmail.com',
            ],
        ]);

        $call = V2Call::query()->create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'prospect_name' => 'Jane Doe',
            'status' => 'booked',
            'scheduled_call_at' => now()->addDays(2)->startOfHour(),
            'meta' => ['prospect_email' => 'jane@example.com'],
        ]);

        $unipile = Mockery::mock(UnipileProvider::class);
        $unipile->shouldReceive('listCalendars')
            ->andThrow(new \App\V2\Integrations\Unipile\UnipileException('Unipile API error (HTTP 400): Invalid parameters', 400));
        $unipile->shouldReceive('createCalendarEvent')
            ->once()
            ->with('uni_123', 'owner@gmail.com', Mockery::type('array'))
            ->andReturn(['id' => 'evt_fallback']);
        $unipile->shouldReceive('getCalendarEvent')
            ->once()
            ->with('uni_123', 'owner@gmail.com', 'evt_fallback')
            ->andReturn(['id' => 'evt_fallback']);

        $this->app->instance(UnipileProvider::class, $unipile);

        $result = app(CallCalendarService::class)->syncEventForCall($call->fresh(), $user);

        $this->assertSame('evt_fallback', $result['event_id']);
    }

    public function test_resolve_calendar_account_honors_selected_provider(): void
    {
        $user = $this->userWithOrg();

        V2IntegrationAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'google_calendar',
            'provider_account_id' => 'uni_google',
            'status' => 'active',
            'meta' => [
                'unipile_account_id' => 'uni_google',
                'unipile_type' => 'GOOGLE_OAUTH',
                'email' => 'owner@gmail.com',
            ],
        ]);

        V2IntegrationAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'outlook_calendar',
            'provider_account_id' => 'uni_outlook',
            'status' => 'active',
            'meta' => [
                'unipile_account_id' => 'uni_outlook',
                'unipile_type' => 'OUTLOOK',
                'email' => 'owner@outlook.com',
            ],
        ]);

        $service = app(CallCalendarService::class);

        $google = $service->resolveCalendarAccount($user->id, 'google_calendar');
        $outlook = $service->resolveCalendarAccount($user->id, 'outlook_calendar');

        $this->assertSame('uni_google', $google['unipile_account_id'] ?? null);
        $this->assertSame('uni_outlook', $outlook['unipile_account_id'] ?? null);

        $accounts = $service->listConnectedCalendarAccounts($user->id);
        $this->assertCount(2, $accounts);
        $this->assertSame(['google_calendar', 'outlook_calendar'], array_column($accounts, 'provider'));
    }

    private function userWithOrg(): User
    {
        $user = User::factory()->create();
        $organization = V2Organization::query()->create([
            'name' => 'Calendar Test Org',
            'slug' => 'calendar-test-org-'.$user->id,
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

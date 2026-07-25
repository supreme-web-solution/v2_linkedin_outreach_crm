<?php

namespace App\V2\Services;

use App\Mail\CallBookedOwnerMail;
use App\Models\User;
use App\Models\V2Call;
use App\Models\V2IntegrationAccount;
use App\Models\V2UserActivity;
use App\V2\Integrations\Unipile\UnipileException;
use App\V2\Integrations\Unipile\UnipileProvider;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class CallCalendarService
{
    public function __construct(private readonly UnipileProvider $unipile)
    {
    }

    public function isAvailable(int $userId): bool
    {
        return $this->resolveCalendarAccount($userId) !== null;
    }

    public function generateBookingToken(): string
    {
        return Str::random(48);
    }

    public function publicBookingUrl(string $token): string
    {
        return url('/book/'.$token);
    }

    /**
     * Booking link for opening messages: manual URL wins, else per-call app link when calendar connected.
     *
     * @param  array<string, mixed>  $settings
     */
    public function resolveBookingUrl(User $user, array $settings, string $bookingToken): string
    {
        $useAppLink = ($settings['use_app_booking_link'] ?? true) !== false;

        if ($useAppLink && $this->isAvailable($user->id)) {
            return $this->publicBookingUrl($bookingToken);
        }

        return trim((string) ($settings['calendar_url'] ?? ''));
    }

    public function findCallByBookingToken(string $token): ?V2Call
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        return V2Call::query()
            ->where('meta->booking_token', $token)
            ->first();
    }

    public function ensureBookingToken(V2Call $call): string
    {
        $meta = is_array($call->meta) ? $call->meta : [];
        $token = trim((string) ($meta['booking_token'] ?? ''));
        if ($token === '') {
            $token = $this->generateBookingToken();
            $meta['booking_token'] = $token;
            $call->forceFill(['meta' => $meta])->save();
        }

        return $token;
    }

    /**
     * @return array{account: V2IntegrationAccount, unipile_account_id: string}|null
     */
    public function resolveCalendarAccount(int $userId): ?array
    {
        $accounts = V2IntegrationAccount::query()
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->whereIn('provider', ['google_calendar', 'outlook_calendar', 'email'])
            ->latest('id')
            ->get();

        foreach ($accounts as $account) {
            $unipileId = $account->getUnipileAccountId();
            if (!$unipileId) {
                continue;
            }

            $hosted = strtoupper((string) Arr::get($account->meta ?? [], 'unipile_type', Arr::get($account->meta ?? [], 'unipile_provider', '')));
            if ($account->provider === 'email' && !in_array($hosted, ['GOOGLE_OAUTH', 'GOOGLE', 'OUTLOOK', 'MICROSOFT'], true)) {
                continue;
            }

            return [
                'account' => $account,
                'unipile_account_id' => $unipileId,
            ];
        }

        return null;
    }

    /**
     * @return list<array{id: string, name: string, primary: bool}>
     */
    public function listCalendarsForUser(int $userId): array
    {
        $resolved = $this->resolveCalendarAccount($userId);
        if (!$resolved) {
            return [];
        }

        try {
            $response = $this->unipile->listCalendars($resolved['unipile_account_id']);
        } catch (UnipileException $e) {
            Log::warning('[CallCalendar] listCalendars failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $items = Arr::get($response, 'items', Arr::get($response, 'data', []));
        if (isset($items['items']) && is_array($items['items'])) {
            $items = $items['items'];
        }
        if (!is_array($items)) {
            return [];
        }

        return collect($items)
            ->map(function ($row) {
                if (!is_array($row)) {
                    return null;
                }

                $id = trim((string) ($row['id'] ?? $row['calendar_id'] ?? ''));
                if ($id === '') {
                    return null;
                }

                return [
                    'id' => $id,
                    'name' => trim((string) ($row['name'] ?? $row['title'] ?? 'Calendar')) ?: 'Calendar',
                    'primary' => (bool) ($row['primary'] ?? $row['is_primary'] ?? false),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return list<array{start: string, end: string, label: string}>
     */
    public function availableSlotsForUser(User $user): array
    {
        $settings = app(CallOrchestrationService::class)->settingsFor($user);
        if (!$this->isAvailable($user->id)) {
            return [];
        }

        $timezone = (string) ($settings['calendar_timezone'] ?? $user->timezone ?? config('app.timezone', 'UTC'));
        $durationMinutes = max(15, min(240, (int) ($settings['call_duration_minutes'] ?? 30)));
        $daysAhead = max(1, min(30, (int) ($settings['booking_days_ahead'] ?? 14)));
        $hourStart = max(0, min(23, (int) ($settings['booking_hours_start'] ?? 9)));
        $hourEnd = max($hourStart + 1, min(24, (int) ($settings['booking_hours_end'] ?? 17)));

        $rangeStart = Carbon::instance(now($timezone)->addHour()->startOfHour());
        $rangeEnd = Carbon::instance(now($timezone)->addDays($daysAhead)->endOfDay());

        $busy = $this->busyIntervalsForUser($user, $rangeStart, $rangeEnd, $timezone);
        $slots = [];

        $cursor = $rangeStart->copy();
        while ($cursor->lt($rangeEnd)) {
            if ($cursor->isWeekend()) {
                $cursor->addDay()->startOfDay();
                continue;
            }

            $dayStart = $cursor->copy()->setTime($hourStart, 0);
            $dayEnd = $cursor->copy()->setTime($hourEnd, 0);
            $slotAt = $dayStart->copy();

            if ($slotAt->lt($rangeStart)) {
                $slotAt = $rangeStart->copy();
            }

            while ($slotAt->copy()->addMinutes($durationMinutes)->lte($dayEnd)) {
                $slotEnd = $slotAt->copy()->addMinutes($durationMinutes);
                if (!$this->overlapsBusy($slotAt, $slotEnd, $busy)) {
                    $slots[] = [
                        'start' => $slotAt->toIso8601String(),
                        'end' => $slotEnd->toIso8601String(),
                        'label' => $slotAt->timezone($timezone)->format('D, M j · g:i A'),
                    ];
                }
                $slotAt->addMinutes($durationMinutes);
            }

            $cursor->addDay()->startOfDay();
        }

        return array_slice($slots, 0, 48);
    }

    /**
     * Book a call from the public page or inbound webhook.
     */
    public function bookCallAt(V2Call $call, User $user, Carbon $start, ?string $source = 'app', ?string $prospectEmail = null): V2Call
    {
        if ($call->scheduled_call_at && $call->status === 'booked') {
            return $call;
        }

        $settings = app(CallOrchestrationService::class)->settingsFor($user);
        $durationMinutes = max(15, min(240, (int) ($settings['call_duration_minutes'] ?? 30)));
        $timezone = (string) ($settings['calendar_timezone'] ?? config('app.timezone', 'UTC'));
        $start = $start->copy()->timezone($timezone);

        if ($source === 'public_booking') {
            $allowed = collect($this->availableSlotsForUser($user))
                ->first(fn (array $slot) => Carbon::parse($slot['start'])->equalTo($start));
            if ($allowed === null) {
                throw new \InvalidArgumentException('That time slot is no longer available. Please pick another.');
            }
        }

        $meta = is_array($call->meta) ? $call->meta : [];
        $meta['booked_via'] = $source;
        $meta['booked_at'] = now()->toIso8601String();

        if ($prospectEmail !== null && trim($prospectEmail) !== '') {
            $meta['prospect_email'] = trim($prospectEmail);
        }

        $call->forceFill([
            'scheduled_call_at' => $start,
            'status' => 'booked',
            'meta' => $meta,
        ])->save();

        try {
            app(CallOrchestrationService::class)->scheduleCallReminders($call->fresh(), $user);
        } catch (\Throwable $e) {
            Log::warning('[CallCalendar] scheduleCallReminders failed after booking', [
                'call_id' => $call->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            $fresh = $call->fresh();
            $errorMeta = is_array($fresh->meta) ? $fresh->meta : [];
            $errorMeta['calendar_sync_error'] = $e->getMessage();
            $fresh->forceFill(['meta' => $errorMeta])->save();
        }

        $this->recordCallBookedActivity($call->fresh(), $user, $source);
        $this->notifyOwnerByEmail($call->fresh(), $user);

        return $call->fresh();
    }

    /**
     * Match inbound Unipile calendar webhooks to pipeline calls.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleInboundCalendarEvent(int $userId, array $payload): bool
    {
        $eventId = trim((string) (
            Arr::get($payload, 'data.id')
            ?? Arr::get($payload, 'data.event_id')
            ?? Arr::get($payload, 'id')
            ?? Arr::get($payload, 'event_id')
            ?? ''
        ));

        if ($eventId !== '') {
            $already = V2Call::query()
                ->where('user_id', $userId)
                ->where('meta->calendar_event_id', $eventId)
                ->exists();
            if ($already) {
                return false;
            }
        }

        $startRaw = Arr::get($payload, 'data.start')
            ?? Arr::get($payload, 'data.start_time')
            ?? Arr::get($payload, 'start')
            ?? Arr::get($payload, 'start_time');
        if (!$startRaw) {
            return false;
        }

        try {
            $start = Carbon::parse((string) $startRaw);
        } catch (\Throwable) {
            return false;
        }

        $title = trim((string) (
            Arr::get($payload, 'data.title')
            ?? Arr::get($payload, 'data.name')
            ?? Arr::get($payload, 'title')
            ?? ''
        ));
        $description = trim((string) (
            Arr::get($payload, 'data.description')
            ?? Arr::get($payload, 'description')
            ?? ''
        ));

        $bookingToken = $this->extractBookingTokenFromText($description.' '.$title);

        $user = User::query()->find($userId);
        if (!$user) {
            return false;
        }

        $call = null;
        if ($bookingToken !== '') {
            $call = $this->findCallByBookingToken($bookingToken);
        }

        if ($call === null && $title !== '') {
            $prospectName = $this->extractProspectNameFromTitle($title);
            if ($prospectName !== '') {
                $call = V2Call::query()
                    ->where('user_id', $userId)
                    ->whereNull('scheduled_call_at')
                    ->whereNotIn('status', ['completed', 'lost', 'failed', 'booked'])
                    ->whereRaw('LOWER(prospect_name) = ?', [strtolower($prospectName)])
                    ->latest('updated_at')
                    ->first();
            }
        }

        if ($call === null) {
            return false;
        }

        if ($eventId !== '') {
            $meta = is_array($call->meta) ? $call->meta : [];
            $meta['calendar_event_id'] = $eventId;
            $call->forceFill(['meta' => $meta])->save();
        }

        $this->bookCallAt($call, $user, $start, 'calendar_webhook');

        Log::info('[CallCalendar] Auto-booked call from calendar webhook', [
            'call_id' => $call->id,
            'user_id' => $userId,
            'event_id' => $eventId,
        ]);

        return true;
    }

    /**
     * @return array{event_id: string|null, html_link: string|null, meeting_url: string|null}|null
     */
    public function syncEventForCall(V2Call $call, User $user): ?array
    {
        if (!$call->scheduled_call_at) {
            return null;
        }

        $meta = is_array($call->meta) ? $call->meta : [];
        if (!empty($meta['calendar_event_id'])) {
            if (empty($meta['meeting_url'])) {
                $this->refreshMeetingLinkForCall($call->fresh(), $user);
                $call->refresh();
                $meta = is_array($call->meta) ? $call->meta : [];
            }

            $currentStart = $this->calendarEventStartUtc($call);
            $syncedStart = trim((string) ($meta['calendar_event_start'] ?? ''));

            if ($syncedStart === $currentStart) {
                return [
                    'event_id' => (string) $meta['calendar_event_id'],
                    'html_link' => Arr::get($meta, 'calendar_html_link'),
                    'meeting_url' => Arr::get($meta, 'meeting_url'),
                ];
            }

            return $this->updateExistingCalendarEventForCall($call->fresh(), $user, $meta);
        }

        $context = $this->resolveCalendarEventContext($call, $user);
        if ($context === null) {
            return null;
        }

        ['body' => $body, 'calendar_id' => $calendarId, 'unipile_account_id' => $unipileAccountId, 'account' => $account] = $context;

        $conferenceProvider = $this->conferenceProviderForAccount($account);
        if ($conferenceProvider !== null) {
            $body['conference'] = ['provider' => $conferenceProvider];
        }

        try {
            $result = $this->unipile->createCalendarEvent(
                $unipileAccountId,
                $calendarId,
                $body,
            );
        } catch (UnipileException $e) {
            Log::error('[CallCalendar] createCalendarEvent failed', [
                'call_id' => $call->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            $meta['calendar_sync_error'] = $e->getMessage();
            $call->forceFill(['meta' => $meta])->save();

            return null;
        }

        return $this->persistCalendarEventResponse($call, $meta, $calendarId, $unipileAccountId, $result);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{event_id: string|null, html_link: string|null, meeting_url: string|null}|null
     */
    private function updateExistingCalendarEventForCall(V2Call $call, User $user, array $meta): ?array
    {
        $context = $this->resolveCalendarEventContext($call, $user);
        if ($context === null) {
            return null;
        }

        ['body' => $body, 'calendar_id' => $calendarId, 'unipile_account_id' => $unipileAccountId] = $context;
        $eventId = trim((string) ($meta['calendar_event_id'] ?? ''));
        if ($eventId === '') {
            return null;
        }

        $storedCalendarId = trim((string) ($meta['calendar_id'] ?? ''));
        if ($storedCalendarId !== '') {
            $calendarId = $storedCalendarId;
        }

        try {
            $result = $this->unipile->updateCalendarEvent(
                $unipileAccountId,
                $calendarId,
                $eventId,
                $body,
            );
        } catch (UnipileException $e) {
            Log::error('[CallCalendar] updateCalendarEvent failed', [
                'call_id' => $call->id,
                'user_id' => $user->id,
                'event_id' => $eventId,
                'error' => $e->getMessage(),
            ]);

            $meta['calendar_sync_error'] = $e->getMessage();
            $call->forceFill(['meta' => $meta])->save();

            return null;
        }

        Log::info('[CallCalendar] Updated calendar event after reschedule', [
            'call_id' => $call->id,
            'event_id' => $eventId,
            'scheduled_call_at' => $call->scheduled_call_at?->toIso8601String(),
        ]);

        $persisted = $this->persistCalendarEventResponse($call, $meta, $calendarId, $unipileAccountId, $result, $eventId);

        if (($persisted['meeting_url'] ?? null) === null) {
            $refreshed = $this->refreshMeetingLinkForCall($call->fresh(), $user);
            if ($refreshed !== null) {
                $call->refresh();
                $meta = is_array($call->meta) ? $call->meta : [];
                $persisted['meeting_url'] = $refreshed;
                $persisted['html_link'] = Arr::get($meta, 'calendar_html_link');
            }
        }

        return $persisted;
    }

    /**
     * @return array{body: array<string, mixed>, calendar_id: string, unipile_account_id: string, account: V2IntegrationAccount}|null
     */
    private function resolveCalendarEventContext(V2Call $call, User $user): ?array
    {
        $settings = app(CallOrchestrationService::class)->settingsFor($user);
        if (($settings['use_unipile_calendar'] ?? true) === false) {
            return null;
        }

        $resolved = $this->resolveCalendarAccount($user->id);
        if (!$resolved) {
            return null;
        }

        $calendarId = trim((string) ($settings['calendar_id'] ?? ''));
        if ($calendarId === '') {
            $calendarId = $this->resolvePrimaryCalendarId($user->id, $resolved['unipile_account_id']);
        }

        if ($calendarId === '') {
            Log::warning('[CallCalendar] syncEventForCall skipped — no calendar_id resolved', [
                'call_id' => $call->id,
                'user_id' => $user->id,
            ]);

            return null;
        }

        return [
            'body' => $this->buildCalendarEventBody($call, $user, $settings),
            'calendar_id' => $calendarId,
            'unipile_account_id' => $resolved['unipile_account_id'],
            'account' => $resolved['account'],
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function buildCalendarEventBody(V2Call $call, User $user, array $settings): array
    {
        $durationMinutes = max(15, min(240, (int) ($settings['call_duration_minutes'] ?? 30)));
        $start = $call->scheduled_call_at->copy();
        $end = $start->copy()->addMinutes($durationMinutes);
        $timezone = $settings['calendar_timezone'] ?? config('app.timezone', 'UTC');

        $prospectName = trim((string) ($call->prospect_name ?? 'Prospect'));
        $hostName = trim((string) ($user->name ?? '')) ?: 'your host';
        $title = "Call with {$hostName}";
        $meta = is_array($call->meta) ? $call->meta : [];
        $bookingToken = trim((string) ($meta['booking_token'] ?? ''));
        $description = "Booked call with {$prospectName}.";
        $headline = trim((string) ($call->prospect_headline ?? ''));
        if ($headline !== '') {
            $description .= "\n{$headline}";
        }
        if ($bookingToken !== '') {
            $description .= "\n\nCall Manager: {$bookingToken}";
        }

        $startUtc = $start->copy()->utc();
        $endUtc = $end->copy()->utc();
        $eventTimezone = $timezone !== '' ? $timezone : 'UTC';

        $body = [
            'title' => $title,
            'body' => $description,
            'start' => [
                'date_time' => $startUtc->format('Y-m-d\TH:i:s.000\Z'),
                'time_zone' => $eventTimezone,
            ],
            'end' => [
                'date_time' => $endUtc->format('Y-m-d\TH:i:s.000\Z'),
                'time_zone' => $eventTimezone,
            ],
            'notify' => true,
        ];

        $attendeeEmail = $this->prospectEmailForCall($call);
        if ($attendeeEmail !== '') {
            $body['attendees'] = [[
                'email' => $attendeeEmail,
                'name' => $prospectName,
            ]];
        }

        return $body;
    }

    private function calendarEventStartUtc(V2Call $call): string
    {
        return $call->scheduled_call_at
            ? $call->scheduled_call_at->copy()->utc()->format('Y-m-d\TH:i:s.000\Z')
            : '';
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $result
     * @return array{event_id: string|null, html_link: string|null, meeting_url: string|null}
     */
    private function persistCalendarEventResponse(
        V2Call $call,
        array $meta,
        string $calendarId,
        string $unipileAccountId,
        array $result,
        ?string $knownEventId = null,
    ): array {
        $eventId = trim((string) (
            $knownEventId
            ?? Arr::get($result, 'id')
            ?? Arr::get($result, 'event_id')
            ?? Arr::get($result, 'data.id')
            ?? ''
        ));
        $htmlLink = $this->extractCalendarHtmlLinkFromPayload($result);
        $meetingUrl = $this->extractMeetingUrlFromPayload($result);

        if ($meetingUrl === '' && $eventId !== '') {
            try {
                $fetched = $this->unipile->getCalendarEvent(
                    $unipileAccountId,
                    $calendarId,
                    $eventId,
                );
                $meetingUrl = $this->extractMeetingUrlFromPayload($fetched);
                if ($htmlLink === '') {
                    $htmlLink = $this->extractCalendarHtmlLinkFromPayload($fetched);
                }
            } catch (UnipileException $e) {
                Log::warning('[CallCalendar] getCalendarEvent failed after create/update', [
                    'call_id' => $call->id,
                    'event_id' => $eventId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $meta['calendar_event_id'] = $eventId !== '' ? $eventId : null;
        $meta['calendar_id'] = $calendarId;
        $meta['calendar_html_link'] = $htmlLink !== '' ? $htmlLink : ($meta['calendar_html_link'] ?? null);
        $meta['meeting_url'] = $meetingUrl !== '' ? $meetingUrl : ($meta['meeting_url'] ?? null);
        $meta['calendar_event_start'] = $this->calendarEventStartUtc($call);
        unset($meta['calendar_sync_error']);

        $call->forceFill(['meta' => $meta])->save();

        return [
            'event_id' => $eventId !== '' ? $eventId : null,
            'html_link' => $htmlLink !== '' ? $htmlLink : null,
            'meeting_url' => $meetingUrl !== '' ? $meetingUrl : null,
        ];
    }

    public function refreshMeetingLinkForCall(V2Call $call, User $user): ?string
    {
        $meta = is_array($call->meta) ? $call->meta : [];
        $eventId = trim((string) ($meta['calendar_event_id'] ?? ''));
        if ($eventId === '') {
            return null;
        }

        $resolved = $this->resolveCalendarAccount($user->id);
        if (!$resolved) {
            return null;
        }

        $settings = app(CallOrchestrationService::class)->settingsFor($user);
        $calendarId = trim((string) ($meta['calendar_id'] ?? $settings['calendar_id'] ?? ''));
        if ($calendarId === '') {
            $calendarId = $this->resolvePrimaryCalendarId($user->id, $resolved['unipile_account_id']);
        }
        if ($calendarId === '') {
            return null;
        }

        try {
            $result = $this->unipile->getCalendarEvent(
                $resolved['unipile_account_id'],
                $calendarId,
                $eventId,
            );
        } catch (UnipileException $e) {
            Log::warning('[CallCalendar] refreshMeetingLinkForCall failed', [
                'call_id' => $call->id,
                'event_id' => $eventId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $meetingUrl = $this->extractMeetingUrlFromPayload($result);
        $htmlLink = $this->extractCalendarHtmlLinkFromPayload($result);

        if ($meetingUrl !== '') {
            $meta['meeting_url'] = $meetingUrl;
        }
        if ($htmlLink !== '') {
            $meta['calendar_html_link'] = $htmlLink;
        }
        $meta['calendar_id'] = $calendarId;

        $call->forceFill(['meta' => $meta])->save();

        return $meetingUrl !== '' ? $meetingUrl : null;
    }

    private function conferenceProviderForAccount(V2IntegrationAccount $account): ?string
    {
        $type = strtoupper((string) Arr::get($account->meta ?? [], 'unipile_type', ''));

        if ($account->provider === 'outlook_calendar' || in_array($type, ['OUTLOOK', 'MICROSOFT'], true)) {
            return 'teams';
        }

        if (in_array($account->provider, ['google_calendar', 'email'], true)
            || in_array($type, ['GOOGLE', 'GOOGLE_OAUTH', 'GMAIL'], true)) {
            return 'google_meet';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractMeetingUrlFromPayload(array $payload): string
    {
        $candidates = [
            Arr::get($payload, 'conference.url'),
            Arr::get($payload, 'conference.join_url'),
            Arr::get($payload, 'conference.link'),
            Arr::get($payload, 'conference.meeting_url'),
            Arr::get($payload, 'hangoutLink'),
            Arr::get($payload, 'hangout_link'),
            Arr::get($payload, 'meet_link'),
            Arr::get($payload, 'meeting_url'),
            Arr::get($payload, 'onlineMeeting.joinUrl'),
            Arr::get($payload, 'online_meeting.join_url'),
            Arr::get($payload, 'data.conference.url'),
            Arr::get($payload, 'data.hangoutLink'),
        ];

        foreach ($candidates as $candidate) {
            $url = trim((string) $candidate);
            if ($url !== '' && $this->looksLikeMeetingUrl($url)) {
                return $url;
            }
        }

        $location = trim((string) (Arr::get($payload, 'location') ?? Arr::get($payload, 'data.location') ?? ''));
        if ($location !== '' && $this->looksLikeMeetingUrl($location)) {
            return $location;
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractCalendarHtmlLinkFromPayload(array $payload): string
    {
        return trim((string) (
            Arr::get($payload, 'html_link')
            ?? Arr::get($payload, 'web_link')
            ?? Arr::get($payload, 'data.html_link')
            ?? Arr::get($payload, 'data.web_link')
            ?? ''
        ));
    }

    private function looksLikeMeetingUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        return (bool) preg_match('#(meet\.google\.com|teams\.microsoft\.com|teams\.live\.com|zoom\.us|webex\.com)#i', $url);
    }

    /**
     * @return list<array{start: Carbon, end: Carbon}>
     */
    private function busyIntervalsForUser(User $user, CarbonInterface $rangeStart, CarbonInterface $rangeEnd, string $timezone): array
    {
        $resolved = $this->resolveCalendarAccount($user->id);
        if (!$resolved) {
            return [];
        }

        $settings = app(CallOrchestrationService::class)->settingsFor($user);
        $calendarId = trim((string) ($settings['calendar_id'] ?? ''));
        if ($calendarId === '') {
            $calendarId = $this->resolvePrimaryCalendarId($user->id, $resolved['unipile_account_id']);
        }
        if ($calendarId === '') {
            return [];
        }

        try {
            $response = $this->unipile->listCalendarEvents($resolved['unipile_account_id'], $calendarId, [
                'start' => $rangeStart->utc()->format('Y-m-d\TH:i:s\Z'),
                'end' => $rangeEnd->utc()->format('Y-m-d\TH:i:s\Z'),
                'busy' => 'true',
                'limit' => 100,
            ]);
        } catch (UnipileException $e) {
            Log::warning('[CallCalendar] listCalendarEvents failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $items = Arr::get($response, 'items', Arr::get($response, 'data', []));
        if (!is_array($items)) {
            return [];
        }

        $intervals = [];
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $startRaw = $row['start'] ?? $row['start_time'] ?? null;
            $endRaw = $row['end'] ?? $row['end_time'] ?? null;
            if (!$startRaw || !$endRaw) {
                continue;
            }
            try {
                $intervals[] = [
                    'start' => Carbon::parse((string) $startRaw)->timezone($timezone),
                    'end' => Carbon::parse((string) $endRaw)->timezone($timezone),
                ];
            } catch (\Throwable) {
                continue;
            }
        }

        return $intervals;
    }

    /**
     * @param  list<array{start: CarbonInterface, end: CarbonInterface}>  $busy
     */
    private function overlapsBusy(CarbonInterface $start, CarbonInterface $end, array $busy): bool
    {
        foreach ($busy as $interval) {
            if ($start->lt($interval['end']) && $end->gt($interval['start'])) {
                return true;
            }
        }

        return false;
    }

    private function resolvePrimaryCalendarId(int $userId, string $unipileAccountId): string
    {
        $calendars = $this->listCalendarsForUser($userId);

        foreach ($calendars as $calendar) {
            if ($calendar['primary']) {
                return $calendar['id'];
            }
        }

        if ($calendars !== []) {
            return $calendars[0]['id'];
        }

        return $this->fallbackCalendarIdForUser($userId);
    }

    private function fallbackCalendarIdForUser(int $userId): string
    {
        $account = V2IntegrationAccount::query()
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->whereIn('provider', ['google_calendar', 'outlook_calendar', 'email'])
            ->latest('id')
            ->first();

        $email = trim((string) Arr::get($account?->meta ?? [], 'email', ''));
        if ($email !== '') {
            Log::info('[CallCalendar] Using connected account email as calendar id fallback', [
                'user_id' => $userId,
            ]);

            return $email;
        }

        Log::info('[CallCalendar] Using "primary" as calendar id fallback', [
            'user_id' => $userId,
        ]);

        return 'primary';
    }

    private function prospectEmailForCall(V2Call $call): string
    {
        if ($call->conversation_id) {
            $conversation = $call->relationLoaded('conversation')
                ? $call->conversation
                : $call->conversation()->with('lead')->first();
            $email = trim((string) Arr::get($conversation?->meta ?? [], 'prospect_email', ''));
            if ($email !== '') {
                return $email;
            }

            $email = trim((string) ($conversation?->lead?->email ?? ''));
            if ($email !== '') {
                return $email;
            }
        }

        return trim((string) Arr::get(is_array($call->meta) ? $call->meta : [], 'prospect_email', ''));
    }

    private function extractBookingTokenFromText(string $text): string
    {
        if (preg_match('/Call Manager:\s*([A-Za-z0-9]{20,64})/', $text, $matches)) {
            return $matches[1];
        }

        return '';
    }

    private function extractProspectNameFromTitle(string $title): string
    {
        if (preg_match('/^Call with\s+(.+)$/i', trim($title), $matches)) {
            return trim($matches[1]);
        }

        return '';
    }

    private function recordCallBookedActivity(V2Call $call, User $user, ?string $source): void
    {
        $organizationId = (int) ($user->current_organization_id ?? $call->organization_id ?? 0);
        if ($organizationId <= 0) {
            return;
        }

        $prospectName = trim((string) ($call->prospect_name ?? ''));
        $scheduledLabel = $call->scheduled_call_at
            ? $call->scheduled_call_at->timezone($user->timezone ?? config('app.timezone'))->format('M j, g:i A')
            : null;

        $identifier = $prospectName !== ''
            ? "{$prospectName} booked a call with you"
            : 'Someone booked a call with you';

        V2UserActivity::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organizationId,
            'module' => 'calls',
            'stat' => 1,
            'identifier' => $identifier,
            'meta' => [
                'call_id' => $call->id,
                'source' => $source,
                'scheduled_at' => $call->scheduled_call_at?->toIso8601String(),
                'scheduled_label' => $scheduledLabel,
                'prospect_name' => $prospectName !== '' ? $prospectName : null,
                'type' => 'call_booked',
            ],
        ]);
    }

    private function notifyOwnerByEmail(V2Call $call, User $user): void
    {
        $ownerEmail = trim((string) ($user->email ?? ''));
        if ($ownerEmail === '') {
            return;
        }

        $prospectEmail = $this->prospectEmailForCall($call);

        try {
            Mail::to($ownerEmail)->send(new CallBookedOwnerMail($user, $call, $prospectEmail));
        } catch (\Throwable $e) {
            Log::warning('[CallCalendar] owner booking email failed', [
                'call_id' => $call->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

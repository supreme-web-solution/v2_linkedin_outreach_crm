<?php

namespace App\V2\Services;

use App\Jobs\V2\LaunchCallFromLeadJob;
use App\Jobs\V2\ProcessOutboundOutreachJob;
use App\Mail\CallReminderProspectMail;
use App\Models\User;
use App\Models\V2Call;
use App\Models\V2Conversation;
use App\Models\V2IntegrationAccount;
use App\Models\V2Message;
use App\Models\V2Reminder;
use App\Services\ChatGPT;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class CallOrchestrationService
{
    /**
     * @return array<string, mixed>
     */
    public function defaultSettings(): array
    {
        return [
            'calendar_url' => '',
            'booking_message' => 'Would you be open to a quick 15-minute call? Here is my calendar: {calendar_url}',
            'auto_send_suggestions' => false,
            'reminder_hours_before' => [24, 1],
            'calendar_id' => '',
            'call_duration_minutes' => 30,
            'use_unipile_calendar' => true,
            'use_app_booking_link' => true,
            'booking_days_ahead' => 14,
            'booking_hours_start' => 9,
            'booking_hours_end' => 17,
            'calendar_timezone' => config('app.timezone', 'UTC'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function settingsFor(User $user): array
    {
        $stored = is_array($user->call_settings) ? $user->call_settings : [];

        return array_merge($this->defaultSettings(), $stored);
    }

    /**
     * Per-flow settings snapshot on the call, falling back to workspace defaults.
     *
     * @return array<string, mixed>
     */
    public function settingsForCall(V2Call $call, User $user): array
    {
        $meta = is_array($call->meta) ? $call->meta : [];
        $flowSettings = $meta['flow_settings'] ?? null;

        if (is_array($flowSettings) && $flowSettings !== []) {
            $settings = array_merge($this->defaultSettings(), $flowSettings);
        } else {
            $settings = $this->settingsFor($user);
        }

        if (array_key_exists('auto_send_suggestions', $meta)) {
            $settings['auto_send_suggestions'] = (bool) $meta['auto_send_suggestions'];
        }

        return $settings;
    }

    public function setAutoSendSuggestions(V2Call $call, bool $enabled): V2Call
    {
        $meta = is_array($call->meta) ? $call->meta : [];
        $meta['auto_send_suggestions'] = $enabled;
        $call->forceFill(['meta' => $meta])->save();

        return $call->fresh();
    }

    /**
     * Update auto-send for every active call in a flow batch.
     * Clears per-call overrides so the batch setting applies uniformly.
     */
    public function setFlowAutoSendSuggestions(int $orgId, ?string $batchId, bool $enabled): int
    {
        $query = V2Call::query()
            ->where('organization_id', $orgId)
            ->whereNotIn('status', ['completed', 'lost', 'failed']);

        if ($batchId === null || $batchId === '') {
            $query->where(function ($q) {
                $q->whereNull('meta->batch_id')
                    ->orWhere('meta->batch_id', '');
            });
        } else {
            $query->where('meta->batch_id', $batchId);
        }

        $updated = 0;
        foreach ($query->get() as $call) {
            /** @var V2Call $call */
            $meta = is_array($call->meta) ? $call->meta : [];
            $flowSettings = is_array($meta['flow_settings'] ?? null) ? $meta['flow_settings'] : [];
            $flowSettings['auto_send_suggestions'] = $enabled;
            $meta['flow_settings'] = $flowSettings;
            unset($meta['auto_send_suggestions']);
            $call->forceFill(['meta' => $meta])->save();
            $updated++;
        }

        return $updated;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function resolveOpeningMessage(array $settings, ?string $override = null): string
    {
        $text = trim((string) ($override ?? ''));
        if ($text !== '') {
            return $text;
        }

        $template = (string) ($settings['booking_message'] ?? $this->defaultSettings()['booking_message']);
        $calendarUrl = trim((string) ($settings['calendar_url'] ?? ''));

        return str_replace('{calendar_url}', $calendarUrl, $template);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function resolveOpeningMessageForCall(User $user, array $settings, ?string $override, string $bookingToken): string
    {
        $text = trim((string) ($override ?? ''));
        if ($text === '') {
            $text = (string) ($settings['booking_message'] ?? $this->defaultSettings()['booking_message']);
        }

        $calendarUrl = app(CallCalendarService::class)->resolveBookingUrl($user, $settings, $bookingToken);

        return str_replace('{calendar_url}', $calendarUrl, $text);
    }

    /**
     * Replace {calendar_url} in the pending opening message when present — never append a link automatically.
     */
    public function ensurePendingMessageHasBookingLink(V2Call $call, User $user): V2Call
    {
        if ($call->conversation_id) {
            return $call;
        }

        $settings = $this->settingsForCall($call, $user);
        $token = app(CallCalendarService::class)->ensureBookingToken($call);

        $text = trim((string) $call->pending_message);
        if ($text === '') {
            $text = $this->resolveOpeningMessageForCall($user, $settings, null, $token);
        } else {
            $text = $this->substituteCalendarPlaceholder($user, $settings, $text, $token);
        }

        if ($text !== trim((string) $call->pending_message)) {
            $call->forceFill(['pending_message' => $text])->save();
        }

        return $call->fresh();
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function substituteCalendarPlaceholder(User $user, array $settings, string $text, string $bookingToken): string
    {
        if (!str_contains($text, '{calendar_url}')) {
            return $text;
        }

        $bookingUrl = app(CallCalendarService::class)->resolveBookingUrl($user, $settings, $bookingToken);
        if ($bookingUrl === '') {
            return $text;
        }

        return str_replace('{calendar_url}', $bookingUrl, $text);
    }

    /**
     * Snapshot workspace defaults for a new call flow (batch).
     *
     * @return array<string, mixed>
     */
    public function snapshotFlowSettings(User $user, ?string $openingOverride = null): array
    {
        $settings = $this->settingsFor($user);

        return [
            'calendar_url' => (string) ($settings['calendar_url'] ?? ''),
            'booking_message' => $openingOverride !== null && trim($openingOverride) !== ''
                ? trim($openingOverride)
                : (string) ($settings['booking_message'] ?? $this->defaultSettings()['booking_message']),
            'auto_send_suggestions' => (bool) ($settings['auto_send_suggestions'] ?? false),
            'reminder_hours_before' => is_array($settings['reminder_hours_before'] ?? null)
                ? $settings['reminder_hours_before']
                : [24, 1],
        ];
    }

    /**
     * @return list<array{
     *     batch_id: string|null,
     *     batch_name: string,
     *     flow_key: string,
     *     count: int,
     *     ready_to_send: int,
     *     auto_send_enabled: bool,
     *     last_message_at: string|null,
     *     stages: array{engaged: int, scheduling: int, booked: int}
     * }>
     */
    public function flowsForOrganization(int $orgId): array
    {
        $calls = V2Call::query()
            ->where('organization_id', $orgId)
            ->whereNotIn('status', ['completed', 'lost', 'failed'])
            ->get();

        return $calls
            ->groupBy(function (V2Call $call) {
                $meta = is_array($call->meta) ? $call->meta : [];

                return (string) (Arr::get($meta, 'batch_id') ?: '_individual');
            })
            ->map(function ($group, $batchKey) {
                /** @var V2Call $first */
                $first = $group->first();
                $meta = is_array($first->meta) ? $first->meta : [];
                $flowSettings = is_array($meta['flow_settings'] ?? null) ? $meta['flow_settings'] : [];

                $conversationIds = $group->pluck('conversation_id')->filter()->unique()->values();
                $lastMessageAt = $conversationIds->isNotEmpty()
                    ? V2Conversation::query()->whereIn('id', $conversationIds)->max('last_message_at')
                    : null;

                $stages = ['engaged' => 0, 'scheduling' => 0, 'booked' => 0];
                foreach ($group as $call) {
                    /** @var V2Call $call */
                    $stage = $this->pipelineStage((string) $call->status);
                    if (isset($stages[$stage])) {
                        $stages[$stage]++;
                    }
                }

                return [
                    'batch_id' => $batchKey === '_individual' ? null : (string) $batchKey,
                    'batch_name' => $batchKey === '_individual'
                        ? 'Individual prospects'
                        : (string) (Arr::get($meta, 'batch_name') ?: 'Untitled flow'),
                    'flow_key' => $batchKey === '_individual' ? 'individual' : (string) $batchKey,
                    'count' => $group->count(),
                    'chats_started' => $group->filter(fn (V2Call $call) => (bool) $call->conversation_id)->count(),
                    'ready_to_send' => $group->filter(
                        fn (V2Call $call) => $call->pending_message
                            && (!$call->scheduled_send_at || $call->scheduled_send_at->isPast())
                    )->count(),
                    'auto_send_enabled' => (bool) ($flowSettings['auto_send_suggestions'] ?? false),
                    'last_message_at' => $lastMessageAt
                        ? \Illuminate\Support\Carbon::parse($lastMessageAt)->toIso8601String()
                        : null,
                    'stages' => $stages,
                ];
            })
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function flowSnapshotForBatch(int $orgId, ?string $batchId): ?array
    {
        $query = V2Call::query()
            ->where('organization_id', $orgId)
            ->whereNotIn('status', ['completed', 'lost', 'failed']);

        if ($batchId === null) {
            $query->where(function ($q) {
                $q->whereNull('meta->batch_id')
                    ->orWhere('meta->batch_id', '');
            });
        } else {
            $query->where('meta->batch_id', $batchId);
        }

        $call = $query->latest('updated_at')->first();
        if (!$call) {
            return null;
        }

        $meta = is_array($call->meta) ? $call->meta : [];
        $flowSettings = $meta['flow_settings'] ?? null;

        return is_array($flowSettings) && $flowSettings !== [] ? $flowSettings : null;
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function saveSettings(User $user, array $settings): User
    {
        $merged = array_merge($this->settingsFor($user), Arr::only($settings, [
            'calendar_url',
            'booking_message',
            'auto_send_suggestions',
            'reminder_hours_before',
            'calendar_id',
            'call_duration_minutes',
            'use_unipile_calendar',
            'use_app_booking_link',
            'booking_days_ahead',
            'booking_hours_start',
            'booking_hours_end',
            'calendar_timezone',
        ]));

        $user->forceFill(['call_settings' => $merged])->save();

        return $user;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createCall(User $user, int $organizationId, array $data): V2Call
    {
        $settings = $this->settingsFor($user);
        $conversationId = isset($data['conversation_id']) ? (int) $data['conversation_id'] : null;
        $conversation = null;

        if ($conversationId) {
            $conversation = V2Conversation::query()
                ->where('user_id', $user->id)
                ->find($conversationId);
            $conversationId = $conversation?->id;
        }

        $bookingToken = app(CallCalendarService::class)->generateBookingToken();
        $pendingMessage = $this->resolveOpeningMessageForCall(
            $user,
            $settings,
            $data['pending_message'] ?? null,
            $bookingToken,
        );

        $prospectName = trim((string) ($data['prospect_name'] ?? ''));
        if ($prospectName === '' && $conversation) {
            $prospectName = trim((string) Arr::get($conversation->meta ?? [], 'prospect_name', ''));
        }

        return V2Call::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organizationId,
            'conversation_id' => $conversationId,
            'connection_id' => isset($data['connection_id']) ? trim((string) $data['connection_id']) : null,
            'prospect_name' => $prospectName !== '' ? $prospectName : null,
            'prospect_headline' => isset($data['prospect_headline']) ? trim((string) $data['prospect_headline']) : null,
            'lead_id' => isset($data['lead_id']) ? (int) $data['lead_id'] : null,
            'status' => 'engaged',
            'pending_message' => $pendingMessage,
            'scheduled_send_at' => now()->addMinutes(5),
            'conversation_history' => [],
            'ai_analysis' => [],
            'meta' => array_merge(
                [
                    'source' => 'crm',
                    'booking_token' => $bookingToken,
                ],
                is_array($data['meta'] ?? null) ? $data['meta'] : [],
            ),
        ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $leads
     * @return array{created: int, skipped: int, launched: int, batch_id: string, call_ids: array<int, int>}
     */
    public function createCallsFromLeads(
        User $user,
        int $organizationId,
        Collection $leads,
        array $options = []
    ): array {
        $pendingMessage = trim((string) ($options['pending_message'] ?? ''));
        $run = (bool) ($options['run'] ?? false);
        $listId = (string) ($options['list_id'] ?? '');
        $src = (string) ($options['src'] ?? '');
        $batchName = trim((string) ($options['batch_name'] ?? ''));
        $batchId = (string) Str::uuid();
        $flowName = $batchName !== '' ? $batchName : 'Untitled flow';
        $flowSettings = $this->snapshotFlowSettings($user, $pendingMessage !== '' ? $pendingMessage : null);
        if (array_key_exists('auto_send_suggestions', $options)) {
            $flowSettings['auto_send_suggestions'] = (bool) $options['auto_send_suggestions'];
        }

        $created = 0;
        $skipped = 0;
        $launched = 0;
        $callIds = [];

        foreach ($leads as $lead) {
            $profileId = trim((string) ($lead['profileid'] ?? ''));
            if ($profileId === '') {
                $skipped++;
                continue;
            }

            $exists = V2Call::query()
                ->where('organization_id', $organizationId)
                ->where('connection_id', $profileId)
                ->whereNotIn('status', ['completed', 'lost', 'failed'])
                ->exists();

            if ($exists) {
                $skipped++;
                continue;
            }

            $call = $this->createCall($user, $organizationId, [
                'connection_id' => $profileId,
                'prospect_name' => (string) ($lead['name'] ?? ''),
                'prospect_headline' => isset($lead['headline']) ? (string) $lead['headline'] : null,
                'pending_message' => $pendingMessage !== '' ? $pendingMessage : null,
                'meta' => [
                    'source' => 'lead_list',
                    'lead_list_id' => $listId,
                    'lead_list_src' => $src,
                    'lead_list_ids' => is_array($options['list_ids'] ?? null) ? $options['list_ids'] : null,
                    'lead_row_id' => (int) ($lead['id'] ?? 0),
                    'batch_id' => $batchId,
                    'batch_name' => $flowName,
                    'flow_settings' => $flowSettings,
                ],
            ]);

            $created++;
            $callIds[] = $call->id;

            if ($run) {
                LaunchCallFromLeadJob::dispatch($call->id);
                $launched++;
            }
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
            'launched' => $launched,
            'batch_id' => $batchId,
            'call_ids' => $callIds,
        ];
    }

    /**
     * Queue LinkedIn chat launch for all unlinked calls in a batch (or individual group).
     *
     * @return array{queued: int, skipped: int}
     */
    public function launchUnlinkedCallsInFlow(User $user, int $organizationId, string $flowKey): array
    {
        $query = V2Call::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->whereNull('conversation_id')
            ->whereNotIn('status', ['completed', 'lost', 'failed']);

        if ($flowKey === 'individual') {
            $query->where(function ($batchQuery) {
                $batchQuery->whereNull('meta->batch_id')
                    ->orWhere('meta->batch_id', '');
            });
        } elseif ($flowKey !== 'all') {
            $query->where('meta->batch_id', $flowKey);
        }

        $queued = 0;
        $skipped = 0;

        // Human-paced launch: stagger each chat with jitter so bulk starts
        // never burst Unipile with back-to-back start_chat calls.
        $stagger = max(1, (int) config('services.unipile_pacing.chat_launch_stagger_seconds', 8));
        $jitterMax = max(0, (int) config('services.unipile_pacing.chat_launch_jitter_seconds', 7));

        foreach ($query->get() as $call) {
            /** @var V2Call $call */
            if (trim((string) $call->connection_id) === '') {
                $skipped++;
                continue;
            }

            $delaySeconds = $queued * $stagger + ($jitterMax > 0 ? random_int(0, $jitterMax) : 0);
            LaunchCallFromLeadJob::dispatch($call->id)->delay(now()->addSeconds($delaySeconds));
            $queued++;
        }

        return ['queued' => $queued, 'skipped' => $skipped];
    }

    public function launchCallChat(V2Call $call, User $user, int $organizationId): bool
    {
        $recipientId = trim((string) $call->connection_id);
        if ($recipientId === '') {
            return false;
        }

        $accountId = V2IntegrationAccount::activeUnipileAccountId($user->id);
        if (!$accountId) {
            return false;
        }

        $settings = $this->settingsForCall($call, $user);
        $token = app(CallCalendarService::class)->ensureBookingToken($call);

        $text = trim((string) $call->pending_message);
        if ($text === '') {
            $text = $this->resolveOpeningMessageForCall($user, $settings, null, $token);
        } else {
            $text = $this->substituteCalendarPlaceholder($user, $settings, $text, $token);
        }

        $persistence = app(OutreachPersistenceService::class);
        $resolved = $persistence->resolveRecipientId($user->id, $organizationId, $recipientId);
        $providerId = trim((string) ($resolved['provider_id'] ?? ''));
        if ($providerId === '') {
            $callMeta = is_array($call->meta) ? $call->meta : [];
            $callMeta['launch_error'] = 'Could not resolve LinkedIn profile for this recipient.';
            $call->forceFill(['meta' => $callMeta])->save();

            return false;
        }

        if ($providerId !== $recipientId) {
            $call->forceFill(['connection_id' => $providerId])->save();
        }

        $profile = is_array($resolved['profile'] ?? null) ? $resolved['profile'] : [];
        $networkDistance = trim((string) (Arr::get($profile, 'network_distance') ?? ''));
        $lead = $persistence->findOrCreateLead($user->id, $providerId, $profile);
        $conversation = $persistence->findOrCreateConversation(
            $user->id,
            $organizationId,
            $lead?->id,
            null,
            [
                'attendee_ids' => [$providerId],
                'prospect_name' => $call->prospect_name,
                'source' => 'call_manager',
            ]
        );

        $callMeta = is_array($call->meta) ? $call->meta : [];
        $callMeta['launch_conversation_id'] = $conversation->id;
        $callMeta['launch_pending_at'] = now()->toIso8601String();
        if ($networkDistance !== '') {
            $callMeta['network_distance'] = $networkDistance;
        }
        unset($callMeta['launch_error'], $callMeta['launch_error_user']);

        $call->forceFill(['meta' => $callMeta])->save();

        $persistence->dispatchOutboundToConversation(
            $user->id,
            $organizationId,
            $conversation,
            $text,
            $providerId,
            ['call_id' => $call->id]
        );

        return true;
    }

    public function rollbackFailedLaunch(int $callId, \Throwable $exception): void
    {
        $call = V2Call::query()->find($callId);
        if (!$call) {
            return;
        }

        $mapped = OutreachUserErrorMapper::map($exception);
        $meta = is_array($call->meta) ? $call->meta : [];
        $orphanConversationId = (int) ($meta['launch_conversation_id'] ?? $call->conversation_id ?? 0);

        $meta['launch_error'] = $mapped['admin_detail'];
        $meta['launch_error_user'] = $mapped['user_message'];
        unset($meta['launch_pending_at'], $meta['launch_conversation_id']);

        $call->forceFill([
            'conversation_id' => null,
            'meta' => $meta,
        ])->save();

        if ($orphanConversationId <= 0) {
            return;
        }

        $conversation = V2Conversation::query()->find($orphanConversationId);
        if (!$conversation || $conversation->provider_chat_id) {
            return;
        }

        $hasSuccessfulMessages = V2Message::query()
            ->where('conversation_id', $conversation->id)
            ->where(function ($query) {
                $query->whereNull('meta->status')
                    ->orWhere('meta->status', '!=', 'failed');
            })
            ->exists();

        if (!$hasSuccessfulMessages) {
            $conversation->delete();
        }
    }

    public function linkConversation(V2Call $call, int $conversationId, User $user): V2Call
    {
        $conversation = V2Conversation::query()
            ->where('user_id', $user->id)
            ->managedByCallManager()
            ->findOrFail($conversationId);

        $updates = ['conversation_id' => $conversation->id];

        if (!$call->prospect_name) {
            $name = trim((string) Arr::get($conversation->meta ?? [], 'prospect_name', ''));
            if ($name !== '') {
                $updates['prospect_name'] = $name;
            }
        }

        $call->forceFill($updates)->save();

        return $call->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function analyzeMessage(string $message): array
    {
        $normalized = strtolower(trim($message));

        if (str_contains($normalized, 'not interested') || str_contains($normalized, 'not intrested')) {
            return [
                'intent' => 'negative',
                'needs_follow_up' => false,
                'should_schedule' => false,
                'analyzed_at' => now()->toIso8601String(),
            ];
        }

        $positiveSignals = ['yes', 'sure', 'great', 'sounds good', 'book', 'schedule', 'available', 'getting there', 'interested'];
        $negativeSignals = ['not now', 'later', 'busy', 'stop', 'unsubscribe', 'no thanks', 'pass'];

        $intent = 'neutral';
        foreach ($positiveSignals as $signal) {
            if (str_contains($normalized, $signal)) {
                $intent = 'positive';
                break;
            }
        }
        if ($intent === 'neutral') {
            foreach ($negativeSignals as $signal) {
                if (str_contains($normalized, $signal)) {
                    $intent = 'negative';
                    break;
                }
            }
        }
        if ($intent === 'neutral' && preg_match('/\bno\b/i', $normalized)) {
            $intent = 'negative';
        }

        return [
            'intent' => $intent,
            'needs_follow_up' => $intent !== 'negative',
            'should_schedule' => $intent === 'positive',
            'analyzed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function regenerateSuggestion(V2Call $call, User $user): array
    {
        $lastInbound = $this->lastInboundMessage($call);
        if ($lastInbound === '') {
            throw new \InvalidArgumentException('No prospect reply to analyze yet.');
        }

        $settings = $this->settingsForCall($call, $user);
        $leadName = $call->prospect_name ?: 'there';
        $thread = $this->conversationThread($call);
        $originalMessage = $this->firstOutboundMessage($call);
        $analysis = $this->analyzeWithAi($thread, $originalMessage, $lastInbound, $leadName);
        $reply = $this->buildSuggestedReply($analysis, $settings, $leadName, $call, $user);
        $stage = $this->stageFromAnalysis($analysis);

        $existing = is_array($call->ai_analysis) ? $call->ai_analysis : [];
        $existing[] = $analysis;

        $call->forceFill([
            'ai_analysis' => $existing,
            'pending_message' => $reply,
            'scheduled_send_at' => now()->addMinutes(5),
            'status' => $stage,
        ])->save();

        return [
            'analysis' => $analysis,
            'reply' => $reply,
            'call' => $call->fresh(),
        ];
    }

    public function lastInboundMessage(V2Call $call): string
    {
        foreach (array_reverse($this->conversationThread($call)) as $entry) {
            if (($entry['sender'] ?? '') !== 'prospect') {
                continue;
            }

            $message = trim((string) ($entry['message'] ?? ''));
            if ($message !== '') {
                return $message;
            }
        }

        if (!$call->conversation_id) {
            return '';
        }

        return trim((string) V2Message::query()
            ->where('conversation_id', $call->conversation_id)
            ->where('direction', 'inbound')
            ->whereNotNull('body')
            ->where('body', '!=', '')
            ->latest('created_at')
            ->value('body'));
    }

    /**
     * Process an inbound LinkedIn reply — AI analysis + suggested next message.
     *
     * @return array<string, mixed>
     */
    public function handleInboundReply(V2Call $call, string $message, User $user, string $sender = 'prospect'): array
    {
        $this->appendConversation($call, $sender, $message);

        $settings = $this->settingsForCall($call, $user);
        $leadName = $call->prospect_name ?: 'there';
        $thread = $this->conversationThread($call);
        $originalMessage = $this->firstOutboundMessage($call);
        $analysis = $this->analyzeWithAi($thread, $originalMessage, $message, $leadName);

        $reply = $this->buildSuggestedReply($analysis, $settings, $leadName, $call, $user);
        $stage = $this->stageFromAnalysis($analysis);

        $scheduledSendAt = now()->addMinutes(5);
        if (($settings['auto_send_suggestions'] ?? false) === true) {
            $scheduledSendAt = now();
        }

        $existing = is_array($call->ai_analysis) ? $call->ai_analysis : [];
        $existing[] = $analysis;

        $call->forceFill([
            'ai_analysis' => $existing,
            'pending_message' => $reply,
            'scheduled_send_at' => $scheduledSendAt,
            'status' => $stage,
        ])->save();

        if ($stage !== 'lost' && !in_array($analysis['current_intent'] ?? '', ['not_interested'], true)) {
            $hasPendingReminder = V2Reminder::query()
                ->where('call_id', $call->id)
                ->where('status', 'pending')
                ->exists();

            if (!$hasPendingReminder) {
                $this->addReminder(
                    $call,
                    $reply,
                    ($analysis['current_intent'] ?? '') === 'available' ? now()->addMinutes(30) : now()->addHours(24),
                    ['reason' => 'inbound_reply', 'intent' => $analysis['current_intent'] ?? 'unknown']
                );
            }
        }

        if (($settings['auto_send_suggestions'] ?? false) === true && $call->conversation_id) {
            $this->sendPendingMessage($call->fresh());
        }

        return [
            'analysis' => $analysis,
            'reply' => $reply,
            'call' => $call->fresh(),
        ];
    }

    /**
     * @param array<string, mixed> $analysis
     */
    public function buildSuggestedReply(array $analysis, array $settings, string $leadName, ?V2Call $call = null, ?User $user = null): string
    {
        $reply = trim((string) ($analysis['suggested_response'] ?? ''));
        if ($reply === '') {
            $reply = $this->nextReplyForIntent((string) ($analysis['intent'] ?? 'neutral'));
        }

        if ($call && $user && str_contains($reply, '{calendar_url}')) {
            $token = trim((string) Arr::get(is_array($call->meta) ? $call->meta : [], 'booking_token', ''));
            if ($token === '') {
                $token = app(CallCalendarService::class)->ensureBookingToken($call);
            }
            $reply = $this->substituteCalendarPlaceholder($user, $settings, $reply, $token);
        }

        return $reply;
    }

    /**
     * @param array<int, array<string, mixed>> $thread
     * @return array<string, mixed>
     */
    public function analyzeWithAi(array $thread, string $originalMessage, string $lastReply, string $leadName): array
    {
        if (config('services.chatgpt.key')) {
            try {
                $analysis = (new ChatGPT)->analyzeConversationThread($thread, $originalMessage, $lastReply, $leadName);
                $analysis['source'] = 'openai';

                if (($analysis['current_intent'] ?? '') !== 'unknown') {
                    return $analysis;
                }
            } catch (\Throwable) {
                // Fall back to local heuristics when OpenAI is unavailable or misconfigured.
            }
        }

        return $this->heuristicAnalysis($lastReply);
    }

    /**
     * @return array<string, mixed>
     */
    private function heuristicAnalysis(string $lastReply): array
    {
        $basic = $this->analyzeMessage($lastReply);
        $normalized = strtolower(trim($lastReply));

        $currentIntent = match ($basic['intent']) {
            'positive' => 'interested',
            'negative' => str_contains($normalized, 'not interested') || str_contains($normalized, 'not intrested')
                ? 'not_interested'
                : 'needs_more_info',
            default => 'neutral',
        };

        $nextAction = match ($currentIntent) {
            'not_interested' => 'end_conversation',
            'interested' => $basic['should_schedule'] ? 'send_calendar' : 'ask_availability',
            default => 'follow_up_later',
        };

        return array_merge($basic, [
            'current_intent' => $currentIntent,
            'next_action' => $nextAction,
            'suggested_response' => $this->nextReplyForIntent(
                $currentIntent === 'not_interested' ? 'not_interested' : (string) $basic['intent']
            ),
            'is_positive' => $basic['intent'] === 'positive',
            'confidence_level' => 'medium',
            'source' => 'heuristic',
        ]);
    }

    /**
     * @param array<string, mixed> $analysis
     */
    public function stageFromAnalysis(array $analysis): string
    {
        $intent = (string) ($analysis['current_intent'] ?? $analysis['intent'] ?? 'neutral');
        $nextAction = (string) ($analysis['next_action'] ?? '');

        if (in_array($intent, ['not_interested'], true) || $nextAction === 'end_conversation') {
            return 'lost';
        }

        if (in_array($nextAction, ['wait_for_booking', 'acknowledge_thanks'], true)) {
            return 'scheduling';
        }

        if (in_array($nextAction, ['send_calendar', 'schedule_call', 'ask_availability'], true)) {
            return 'scheduling';
        }

        return 'engaged';
    }

    public function pipelineStage(string $status): string
    {
        return match ($status) {
            'scheduling', 'sent', 'in_progress' => 'scheduling',
            'booked' => 'booked',
            'completed' => 'completed',
            'lost', 'failed' => 'lost',
            default => 'engaged',
        };
    }

    public function nextReplyForIntent(string $intent): string
    {
        return match ($intent) {
            'positive', 'interested', 'available' => 'Great — what day works best for a quick call this week?',
            'negative', 'not_interested' => 'Totally understand. I will pause follow-ups — reach out anytime if timing changes.',
            default => 'Thanks for the reply. Happy to share a quick overview if useful.',
        };
    }

    public function appendConversation(V2Call $call, string $sender, string $message): V2Call
    {
        $history = is_array($call->conversation_history) ? $call->conversation_history : [];
        $history[] = [
            'sender' => $sender,
            'message' => $message,
            'at' => now()->toIso8601String(),
        ];

        $call->forceFill([
            'conversation_history' => $history,
        ])->save();

        return $call;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function conversationThread(V2Call $call): array
    {
        $thread = [];

        if ($call->conversation_id) {
            $conversation = V2Conversation::query()->with('messages')->find($call->conversation_id);
            if ($conversation) {
                $thread = $conversation->messages
                    ->sortBy('created_at')
                    ->map(fn ($m) => [
                        'sender' => $this->resolveMessageSender($m, $call),
                        'message' => (string) $m->body,
                        'at' => ($m->received_at ?? $m->sent_at ?? $m->created_at)?->toIso8601String(),
                    ])
                    ->filter(fn (array $entry) => trim((string) ($entry['message'] ?? '')) !== '')
                    ->values()
                    ->all();
            }
        }

        foreach (is_array($call->conversation_history) ? $call->conversation_history : [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $message = trim((string) ($entry['message'] ?? ''));
            if ($message === '') {
                continue;
            }

            $duplicate = collect($thread)->contains(
                fn (array $existing) => trim((string) ($existing['message'] ?? '')) === $message
                    && ($existing['sender'] ?? '') === ($entry['sender'] ?? '')
            );

            if (!$duplicate) {
                $thread[] = $entry;
            }
        }

        usort($thread, fn (array $a, array $b) => strcmp((string) ($a['at'] ?? ''), (string) ($b['at'] ?? '')));

        return $this->dedupeConversationThread($thread);
    }

    private function resolveMessageSender(V2Message $message, V2Call $call): string
    {
        if ($message->direction === 'outbound') {
            return 'user';
        }

        $body = trim((string) $message->body);
        if ($body === '') {
            return 'prospect';
        }

        if ($this->messageLoggedAsUser($call, $body)) {
            return 'user';
        }

        $hasOutboundTwin = V2Message::query()
            ->where('conversation_id', $message->conversation_id)
            ->where('direction', 'outbound')
            ->where('body', $body)
            ->where('id', '!=', $message->id)
            ->exists();

        if ($hasOutboundTwin) {
            return 'user';
        }

        return 'prospect';
    }

    private function messageLoggedAsUser(V2Call $call, string $body): bool
    {
        foreach (is_array($call->conversation_history) ? $call->conversation_history : [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (($entry['sender'] ?? '') === 'user' && trim((string) ($entry['message'] ?? '')) === $body) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $thread
     * @return array<int, array<string, mixed>>
     */
    private function dedupeConversationThread(array $thread): array
    {
        $deduped = [];

        foreach ($thread as $entry) {
            $message = trim((string) ($entry['message'] ?? ''));
            $sender = (string) ($entry['sender'] ?? '');

            if ($sender === 'prospect' && $message !== '' && $this->threadContainsSenderMessage($deduped, 'user', $message)) {
                continue;
            }

            if (!$this->threadContainsSenderMessage($deduped, $sender, $message)) {
                $deduped[] = $entry;
            }
        }

        return $deduped;
    }

    /**
     * @param  array<int, array<string, mixed>>  $thread
     */
    private function threadContainsSenderMessage(array $thread, string $sender, string $message): bool
    {
        return collect($thread)->contains(
            fn (array $existing) => trim((string) ($existing['message'] ?? '')) === $message
                && ($existing['sender'] ?? '') === $sender
        );
    }

    public function firstOutboundMessage(V2Call $call): string
    {
        foreach ($this->conversationThread($call) as $entry) {
            if (($entry['sender'] ?? '') !== 'prospect') {
                return (string) ($entry['message'] ?? '');
            }
        }

        return (string) ($call->pending_message ?? '');
    }

    public function addReminder(
        V2Call $call,
        string $message,
        ?\DateTimeInterface $sendAt = null,
        array $meta = []
    ): V2Reminder {
        return V2Reminder::query()->create([
            'user_id' => $call->user_id,
            'organization_id' => $call->organization_id,
            'call_id' => $call->id,
            'status' => 'pending',
            'message' => $message,
            'send_at' => $sendAt ?? now()->addHours(24),
            'meta' => $meta,
        ]);
    }

    public function scheduleCallReminders(V2Call $call, User $user): void
    {
        if (!$call->scheduled_call_at) {
            return;
        }

        V2Reminder::query()
            ->where('call_id', $call->id)
            ->where('status', 'pending')
            ->where('meta->type', 'pre_call')
            ->update(['status' => 'cancelled']);

        $settings = $this->settingsForCall($call, $user);
        $hours = is_array($settings['reminder_hours_before'] ?? null)
            ? $settings['reminder_hours_before']
            : [24, 1];

        $name = $call->prospect_name ?: 'there';

        foreach ($hours as $hoursBefore) {
            $hoursBefore = max(1, (int) $hoursBefore);
            $sendAt = $call->scheduled_call_at->copy()->subHours($hoursBefore);
            if ($sendAt->isPast()) {
                continue;
            }

            $exists = V2Reminder::query()
                ->where('call_id', $call->id)
                ->where('status', 'pending')
                ->where('send_at', $sendAt)
                ->exists();

            if ($exists) {
                continue;
            }

            $this->addReminder(
                $call,
                "Hi {$name}, looking forward to our call in about {$hoursBefore}h. See you then!",
                $sendAt,
                [
                    'type' => 'pre_call',
                    'hours_before' => $hoursBefore,
                ]
            );
        }

        app(CallCalendarService::class)->syncEventForCall($call->fresh(), $user);
    }

    public function sendPendingMessage(V2Call $call): bool
    {
        $text = trim((string) $call->pending_message);
        if ($text === '' || !$call->conversation_id) {
            return false;
        }

        $conversation = V2Conversation::query()->find($call->conversation_id);
        if (!$conversation?->provider_chat_id) {
            return false;
        }

        $persistence = app(OutreachPersistenceService::class);
        $message = $persistence->createOutboundMessage(
            $conversation->id,
            $text,
            'call_manager',
            ['call_id' => $call->id]
        );

        ProcessOutboundOutreachJob::dispatch(
            'message',
            $call->user_id,
            $call->organization_id,
            $conversation->id,
            $message->id,
            ['chat_id' => $conversation->provider_chat_id, 'text' => $text]
        );

        $this->appendConversation($call, 'user', $text);

        $call->forceFill([
            'pending_message' => null,
            'scheduled_send_at' => null,
            'status' => $this->pipelineStage($call->status) === 'engaged' ? 'scheduling' : $call->status,
        ])->save();

        return true;
    }

    public function sendReminder(V2Reminder $reminder): bool
    {
        $call = $reminder->call;
        if (!$call) {
            return false;
        }

        $channels = [];

        if ($call->conversation_id && $this->dispatchReminderViaLinkedIn($reminder)) {
            $channels[] = 'linkedin';
        }

        if ($this->dispatchReminderViaEmail($reminder)) {
            $channels[] = 'email';
        }

        if ($channels === []) {
            return false;
        }

        $meta = is_array($reminder->meta) ? $reminder->meta : [];
        $meta['sent_channels'] = $channels;
        $meta['sent_channel'] = count($channels) > 1 ? 'linkedin_and_email' : $channels[0];

        $reminder->forceFill([
            'status' => 'sent',
            'sent_at' => now(),
            'meta' => $meta,
        ])->save();

        return true;
    }

    private function dispatchReminderViaLinkedIn(V2Reminder $reminder): bool
    {
        $call = $reminder->call;
        if (!$call?->conversation_id) {
            return false;
        }

        $conversation = V2Conversation::query()->find($call->conversation_id);
        if (!$conversation?->provider_chat_id) {
            return false;
        }

        $text = trim((string) $reminder->message);
        if ($text === '') {
            return false;
        }

        $persistence = app(OutreachPersistenceService::class);
        $message = $persistence->createOutboundMessage(
            $conversation->id,
            $text,
            'call_reminder',
            ['call_id' => $call->id, 'reminder_id' => $reminder->id]
        );

        ProcessOutboundOutreachJob::dispatch(
            'message',
            $call->user_id,
            $call->organization_id,
            $conversation->id,
            $message->id,
            ['chat_id' => $conversation->provider_chat_id, 'text' => $text]
        );

        return true;
    }

    private function dispatchReminderViaEmail(V2Reminder $reminder): bool
    {
        $call = $reminder->call;
        if (!$call) {
            return false;
        }

        $prospectEmail = $this->prospectEmailForCall($call);
        if ($prospectEmail === '') {
            return false;
        }

        $text = trim((string) $reminder->message);
        if ($text === '') {
            return false;
        }

        $host = User::query()->find($call->user_id);
        $hostName = trim((string) ($host?->name ?? '')) ?: 'your host';
        $prospectName = trim((string) ($call->prospect_name ?? '')) ?: 'there';
        $timezone = config('app.timezone', 'UTC');
        $scheduledLabel = $call->scheduled_call_at
            ? $call->scheduled_call_at->timezone($timezone)->format('l, F j, Y \a\t g:i A T')
            : null;

        try {
            Mail::to($prospectEmail)->send(new CallReminderProspectMail(
                $prospectName,
                $hostName,
                $text,
                $scheduledLabel,
            ));
        } catch (\Throwable $e) {
            Log::warning('[CallReminder] prospect email failed', [
                'reminder_id' => $reminder->id,
                'call_id' => $call->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        return true;
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

    public function dispatchDue(): array
    {
        $messagesSent = 0;
        $remindersSent = 0;

        V2Call::query()
            ->whereNotNull('pending_message')
            ->whereNotNull('conversation_id')
            ->whereIn('status', ['pending', 'engaged', 'scheduling', 'in_progress'])
            ->where(function ($query) {
                $query->whereNull('scheduled_send_at')
                    ->orWhere('scheduled_send_at', '<=', now());
            })
            ->limit(50)
            ->get()
            ->each(function (V2Call $call) use (&$messagesSent) {
                if ($this->sendPendingMessage($call)) {
                    $messagesSent++;
                }
            });

        V2Reminder::query()
            ->where('status', 'pending')
            ->where('send_at', '<=', now())
            ->limit(50)
            ->get()
            ->each(function (V2Reminder $reminder) use (&$remindersSent) {
                if ($this->sendReminder($reminder)) {
                    $remindersSent++;
                } elseif (
                    !$reminder->call
                    || (
                        !$reminder->call->conversation_id
                        && $this->prospectEmailForCall($reminder->call) === ''
                    )
                ) {
                    $reminder->forceFill(['status' => 'skipped'])->save();
                }
            });

        return ['messages_sent' => $messagesSent, 'reminders_sent' => $remindersSent];
    }
}

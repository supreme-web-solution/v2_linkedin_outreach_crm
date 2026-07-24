<?php

namespace App\V2\Services;

use App\Jobs\V2\LaunchCallFromLeadJob;
use App\Jobs\V2\ProcessOutboundOutreachJob;
use App\Models\User;
use App\Models\V2Call;
use App\Models\V2Conversation;
use App\Models\V2IntegrationAccount;
use App\Models\V2Message;
use App\Models\V2Reminder;
use App\Services\ChatGPT;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
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
     * @param array<string, mixed> $settings
     */
    public function saveSettings(User $user, array $settings): User
    {
        $merged = array_merge($this->settingsFor($user), Arr::only($settings, [
            'calendar_url',
            'booking_message',
            'auto_send_suggestions',
            'reminder_hours_before',
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

        $pendingMessage = trim((string) ($data['pending_message'] ?? ''));
        if ($pendingMessage === '') {
            $template = (string) ($settings['booking_message'] ?? $this->defaultSettings()['booking_message']);
            $pendingMessage = str_replace('{calendar_url}', (string) ($settings['calendar_url'] ?? ''), $template);
        }

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
                ['source' => 'crm'],
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
                    'lead_row_id' => (int) ($lead['id'] ?? 0),
                    'batch_id' => $batchId,
                    'batch_name' => $batchName !== '' ? $batchName : null,
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

        $text = trim((string) $call->pending_message);
        if ($text === '') {
            $settings = $this->settingsFor($user);
            $template = (string) ($settings['booking_message'] ?? $this->defaultSettings()['booking_message']);
            $text = str_replace('{calendar_url}', (string) ($settings['calendar_url'] ?? ''), $template);
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

        $call->forceFill(['conversation_id' => $conversation->id])->save();

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

        $settings = $this->settingsFor($user);
        $leadName = $call->prospect_name ?: 'there';
        $thread = $this->conversationThread($call);
        $originalMessage = $this->firstOutboundMessage($call);
        $analysis = $this->analyzeWithAi($thread, $originalMessage, $lastInbound, $leadName);
        $reply = $this->buildSuggestedReply($analysis, $settings, $leadName);
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

        $settings = $this->settingsFor($user);
        $leadName = $call->prospect_name ?: 'there';
        $thread = $this->conversationThread($call);
        $originalMessage = $this->firstOutboundMessage($call);
        $analysis = $this->analyzeWithAi($thread, $originalMessage, $message, $leadName);

        $reply = $this->buildSuggestedReply($analysis, $settings, $leadName);
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
    public function buildSuggestedReply(array $analysis, array $settings, string $leadName): string
    {
        $reply = trim((string) ($analysis['suggested_response'] ?? ''));
        if ($reply === '') {
            $reply = $this->nextReplyForIntent((string) ($analysis['intent'] ?? 'neutral'));
        }

        $nextAction = (string) ($analysis['next_action'] ?? '');
        $calendarUrl = trim((string) ($settings['calendar_url'] ?? ''));

        if (in_array($nextAction, ['send_calendar', 'schedule_call', 'ask_availability'], true) && $calendarUrl !== '') {
            if (!str_contains(strtolower($reply), strtolower($calendarUrl))) {
                $template = (string) ($settings['booking_message'] ?? '');
                if ($template !== '' && str_contains($template, '{calendar_url}')) {
                    $reply = str_replace('{calendar_url}', $calendarUrl, $template);
                } elseif (!str_contains(strtolower($reply), 'calendar') && !str_contains(strtolower($reply), 'schedule')) {
                    $reply .= "\n\n".$calendarUrl;
                }
            }
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

        $settings = $this->settingsFor($user);
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
                ['type' => 'pre_call', 'hours_before' => $hoursBefore]
            );
        }
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

        $reminder->forceFill([
            'status' => 'sent',
            'sent_at' => now(),
        ])->save();

        return true;
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
                } elseif (!$reminder->call?->conversation_id) {
                    $reminder->forceFill(['status' => 'skipped'])->save();
                }
            });

        return ['messages_sent' => $messagesSent, 'reminders_sent' => $remindersSent];
    }
}

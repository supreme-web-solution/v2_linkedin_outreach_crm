<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Jobs\V2\LaunchCallFromLeadJob;
use App\Models\User;
use App\Models\V2Call;
use App\Models\V2Conversation;
use App\Models\V2IntegrationAccount;
use App\Models\V2Message;
use App\Models\V2Reminder;
use App\V2\Services\CallCalendarService;
use App\V2\Services\CallOrchestrationService;
use App\V2\Services\LeadListService;
use App\V2\Services\OutreachUserErrorMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CallsWebController extends Controller
{
    public function __construct(
        private readonly CallOrchestrationService $orchestration,
        private readonly LeadListService $leadLists,
        private readonly CallCalendarService $calendar,
    ) {
    }

    public function index(): Response
    {
        /** @var User $user */
        $user = auth()->user();
        $orgId = (int) $user->current_organization_id;
        $pipelineLimit = 5;

        $pipelineTotals = $orgId ? [
            'engaged' => $this->countPipelineStage($orgId, 'engaged'),
            'scheduling' => $this->countPipelineStage($orgId, 'scheduling'),
            'booked' => $this->countPipelineStage($orgId, 'booked'),
        ] : ['engaged' => 0, 'scheduling' => 0, 'booked' => 0];

        $pipeline = $orgId ? [
            'engaged' => $this->callsForPipelineStage($orgId, 'engaged', $pipelineLimit),
            'scheduling' => $this->callsForPipelineStage($orgId, 'scheduling', $pipelineLimit),
            'booked' => $this->callsForPipelineStage($orgId, 'booked', $pipelineLimit),
        ] : [
            'engaged' => collect(),
            'scheduling' => collect(),
            'booked' => collect(),
        ];

        $upcomingQuery = $orgId
            ? V2Call::where('organization_id', $orgId)
                ->where('status', 'booked')
                ->where('scheduled_call_at', '>=', now())
            : null;

        $upcomingTotal = $upcomingQuery ? (int) $upcomingQuery->count() : 0;

        $upcoming = $upcomingQuery
            ? $upcomingQuery
                ->orderBy('scheduled_call_at')
                ->limit(4)
                ->get()
                ->map(fn (V2Call $c) => $this->serializeCall($c))
            : collect();

        $dueReminders = $orgId
            ? V2Reminder::where('organization_id', $orgId)
                ->where('status', 'pending')
                ->where('send_at', '<=', now()->addHours(24))
                ->with('call')
                ->orderBy('send_at')
                ->limit(5)
                ->get()
                ->map(fn (V2Reminder $r) => [
                    'id' => $r->id,
                    'message' => $r->message,
                    'send_at' => $r->send_at?->toIso8601String(),
                    'status' => $r->status,
                    'call_id' => $r->call_id,
                    'prospect_name' => $r->call?->prospect_name,
                ])
            : collect();

        $stats = $orgId ? [
            'in_pipeline' => V2Call::where('organization_id', $orgId)->whereNotIn('status', ['completed', 'lost', 'failed'])->count(),
            'booked' => V2Call::where('organization_id', $orgId)->where('status', 'booked')->count(),
            'ready_to_send' => V2Call::where('organization_id', $orgId)
                ->whereNotNull('pending_message')
                ->where(function ($q) {
                    $q->whereNull('scheduled_send_at')->orWhere('scheduled_send_at', '<=', now());
                })
                ->count(),
            'calls_today' => V2Call::where('organization_id', $orgId)
                ->where('status', 'booked')
                ->whereDate('scheduled_call_at', today())
                ->count(),
        ] : ['in_pipeline' => 0, 'booked' => 0, 'ready_to_send' => 0, 'calls_today' => 0];

        return Inertia::render('crm/Calls/Index', [
            'pipeline' => $pipeline,
            'pipelineTotals' => $pipelineTotals,
            'pipelineLimit' => $pipelineLimit,
            'upcoming' => $upcoming,
            'upcomingTotal' => $upcomingTotal,
            'dueReminders' => $dueReminders,
            'stats' => $stats,
            'settings' => $this->orchestration->settingsFor($user),
            'hasOrg' => (bool) $orgId,
            'hasUnipile' => (bool) V2IntegrationAccount::activeUnipileAccountId($user->id),
            'hasCalendarIntegration' => $this->calendar->isAvailable($user->id),
            'calendarOptions' => $this->calendar->listCalendarsForUser($user->id),
            'leadLists' => $this->leadLists->listsForUser($user->id)->values()->all(),
            'conversations' => $orgId ? $this->conversationOptionsForUser($user->id) : [],
            'flows' => $orgId ? $this->orchestration->flowsForOrganization($orgId) : [],
        ]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function callsForPipelineStage(int $orgId, string $stage, int $limit): \Illuminate\Support\Collection
    {
        $query = V2Call::query()
            ->where('organization_id', $orgId)
            ->whereNotIn('status', ['completed', 'lost', 'failed']);

        $this->applyPipelineStageScope($query, $stage);

        return $query
            ->latest('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (V2Call $c) => $this->serializeCall($c));
    }

    private function countPipelineStage(int $orgId, string $stage): int
    {
        $query = V2Call::query()
            ->where('organization_id', $orgId)
            ->whereNotIn('status', ['completed', 'lost', 'failed']);

        $this->applyPipelineStageScope($query, $stage);

        return (int) $query->count();
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<V2Call>  $query
     */
    private function applyPipelineStageScope(\Illuminate\Database\Eloquent\Builder $query, string $stage): void
    {
        match ($stage) {
            'scheduling' => $query->whereIn('status', ['scheduling', 'sent', 'in_progress']),
            'booked' => $query->where('status', 'booked'),
            default => $query->whereNotIn('status', ['scheduling', 'sent', 'in_progress', 'booked']),
        };
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = auth()->user();
        $orgId = (int) $user->current_organization_id;

        if (!$orgId) {
            return back()->with('error', 'Connect your workspace first.');
        }

        $data = $request->validate([
            'conversation_id' => ['nullable', 'integer'],
            'prospect_name' => ['nullable', 'string', 'max:191'],
            'pending_message' => ['nullable', 'string'],
        ]);

        $this->orchestration->createCall($user, $orgId, $data);

        return redirect()->route('calls')->with('success', 'Call prospect added to pipeline.');
    }

    public function upcomingBooked(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();
        $orgId = (int) $user->current_organization_id;

        if (!$orgId) {
            return response()->json([
                'data' => [],
                'total' => 0,
                'page' => 1,
                'per_page' => 10,
                'has_more' => false,
            ]);
        }

        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $page = max(1, (int) ($data['page'] ?? 1));
        $perPage = max(1, min(20, (int) ($data['per_page'] ?? 10)));

        $query = V2Call::query()
            ->where('organization_id', $orgId)
            ->where('status', 'booked')
            ->where('scheduled_call_at', '>=', now())
            ->orderBy('scheduled_call_at');

        $total = (int) $query->count();
        $items = $query
            ->forPage($page, $perPage)
            ->get()
            ->map(fn (V2Call $c) => $this->serializeCall($c))
            ->values()
            ->all();

        return response()->json([
            'data' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => ($page * $perPage) < $total,
        ]);
    }

    public function listLeads(Request $request, string $listId): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $data = $request->validate([
            'src' => ['required', 'in:aud,sn'],
            'search' => ['nullable', 'string', 'max:191'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $paginator = $this->leadLists->paginateLeads(
            $user->id,
            $listId,
            $data['src'],
            (string) ($data['search'] ?? ''),
            20
        );

        return response()->json([
            'data' => $paginator->items(),
            'total' => $paginator->total(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
        ]);
    }

    public function storeFromLeads(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = auth()->user();
        $orgId = (int) $user->current_organization_id;

        if (!$orgId) {
            return back()->with('error', 'Connect your workspace first.');
        }

        $data = $request->validate([
            'list_id' => ['nullable', 'string', 'required_without:lists'],
            'src' => ['nullable', 'in:aud,sn', 'required_with:list_id'],
            'lists' => ['nullable', 'array', 'required_without:list_id'],
            'lists.*.list_id' => ['required_with:lists', 'string'],
            'lists.*.src' => ['required_with:lists', 'in:aud,sn'],
            'lists.*.select_all' => ['nullable', 'boolean'],
            'lists.*.lead_ids' => ['nullable', 'array'],
            'lists.*.lead_ids.*' => ['integer'],
            'select_all' => ['nullable', 'boolean'],
            'lead_ids' => ['nullable', 'array'],
            'lead_ids.*' => ['integer'],
            'batch_name' => ['required', 'string', 'max:191'],
            'pending_message' => ['nullable', 'string'],
            'run' => ['nullable', 'boolean'],
        ]);

        $lists = is_array($data['lists'] ?? null) ? $data['lists'] : null;

        if ($lists !== null && $lists !== []) {
            $hasSelection = false;
            foreach ($lists as $list) {
                if ((bool) ($list['select_all'] ?? false) || !empty($list['lead_ids'])) {
                    $hasSelection = true;
                    break;
                }
            }
            if (!$hasSelection) {
                return back()->with('error', 'Select at least one lead across your lists, or choose select all on a list.');
            }

            $leads = $this->leadLists->resolveLeadsFromLists($user->id, $lists);
            $listMeta = [
                'list_id' => (string) ($lists[0]['list_id'] ?? ''),
                'src' => (string) ($lists[0]['src'] ?? ''),
                'list_ids' => array_map(fn (array $list) => [
                    'list_id' => $list['list_id'],
                    'src' => $list['src'],
                ], $lists),
            ];
        } else {
            $selectAll = (bool) ($data['select_all'] ?? false);
            $leadIds = array_map('intval', $data['lead_ids'] ?? []);

            if (!$selectAll && $leadIds === []) {
                return back()->with('error', 'Select at least one lead or choose select all.');
            }

            $leads = $this->leadLists->resolveLeads(
                $user->id,
                (string) $data['list_id'],
                (string) $data['src'],
                $leadIds,
                $selectAll,
            );
            $listMeta = [
                'list_id' => (string) $data['list_id'],
                'src' => (string) $data['src'],
            ];
        }

        if (($data['run'] ?? false) && !V2IntegrationAccount::activeUnipileAccountId($user->id)) {
            return back()->with('error', 'Connect LinkedIn under Integrations before starting chats.');
        }

        if ($leads->isEmpty()) {
            return back()->with('error', 'No leads found in the selected list(s).');
        }

        $result = $this->orchestration->createCallsFromLeads($user, $orgId, $leads, array_merge($listMeta, [
            'batch_name' => $data['batch_name'] ?? null,
            'pending_message' => $data['pending_message'] ?? null,
            'run' => (bool) ($data['run'] ?? false),
        ]));

        $message = "{$result['created']} prospect(s) added to Call Manager.";
        if ($result['skipped'] > 0) {
            $message .= " {$result['skipped']} skipped (missing profile or already in pipeline).";
        }
        if ($result['launched'] > 0) {
            $message .= " {$result['launched']} chat(s) queued via Unipile.";
        }

        return redirect()->route('calls')->with('success', $message);
    }

    public function linkConversation(Request $request, int $id): RedirectResponse
    {
        /** @var User $user */
        $user = auth()->user();
        $orgId = (int) $user->current_organization_id;

        $call = V2Call::where('organization_id', $orgId)->where('id', $id)->firstOrFail();

        $data = $request->validate([
            'conversation_id' => ['required', 'integer'],
        ]);

        $this->orchestration->linkConversation($call, (int) $data['conversation_id'], $user);

        return back()->with('success', 'LinkedIn conversation linked.');
    }

    public function launchChat(Request $request, int $id): RedirectResponse
    {
        /** @var User $user */
        $user = auth()->user();
        $orgId = (int) $user->current_organization_id;

        $call = V2Call::where('organization_id', $orgId)->where('id', $id)->firstOrFail();

        if ($call->conversation_id) {
            return back()->with('error', 'LinkedIn chat is already started for this prospect.');
        }

        if ($request->filled('pending_message')) {
            $call->forceFill(['pending_message' => $request->string('pending_message')->toString()])->save();
            $call = $call->fresh();
        }

        if (trim((string) $call->connection_id) === '') {
            return back()->with('error', 'This prospect is missing a LinkedIn profile ID.');
        }

        if (!V2IntegrationAccount::activeUnipileAccountId($user->id)) {
            return back()->with('error', 'Connect LinkedIn under Integrations before starting chats.');
        }

        LaunchCallFromLeadJob::dispatch($call->id);

        return back()->with('success', 'LinkedIn chat queued — your opening message will send shortly.');
    }

    public function launchFlowChats(Request $request, string $flowKey): RedirectResponse
    {
        /** @var User $user */
        $user = auth()->user();
        $orgId = (int) $user->current_organization_id;

        if (!$orgId) {
            return back()->with('error', 'Connect your workspace first.');
        }

        if (!V2IntegrationAccount::activeUnipileAccountId($user->id)) {
            return back()->with('error', 'Connect LinkedIn under Integrations before starting chats.');
        }

        $result = $this->orchestration->launchUnlinkedCallsInFlow($user, $orgId, $flowKey);

        if ($result['queued'] === 0) {
            return back()->with('error', 'No prospects need a chat started in this flow.');
        }

        $message = "{$result['queued']} chat(s) queued via Unipile.";
        if ($result['skipped'] > 0) {
            $message .= " {$result['skipped']} skipped (missing profile).";
        }

        return back()->with('success', $message);
    }

    public function show(int $id): Response
    {
        /** @var User $user */
        $user = auth()->user();
        $orgId = (int) $user->current_organization_id;

        $call = V2Call::where('organization_id', $orgId)
            ->where('id', $id)
            ->with(['reminders' => fn ($q) => $q->orderBy('send_at')])
            ->firstOrFail();

        $messages = collect($this->orchestration->conversationThread($call))
            ->values()
            ->map(fn (array $entry, int $i) => [
                'id' => $i,
                'direction' => ($entry['sender'] ?? '') === 'prospect' ? 'inbound' : 'outbound',
                'body' => (string) ($entry['message'] ?? ''),
                'at' => $entry['at'] ?? null,
            ]);

        $latestAnalysis = is_array($call->ai_analysis) ? collect($call->ai_analysis)->last() : null;
        $bookingToken = $this->calendar->ensureBookingToken($call);
        $settings = $this->orchestration->settingsFor($user);
        $bookingUrl = $this->calendar->resolveBookingUrl($user, $settings, $bookingToken);
        $call = $this->orchestration->ensurePendingMessageHasBookingLink($call->fresh(), $user);
        $bookingUrl = $this->calendar->resolveBookingUrl($user, $settings, $this->calendar->ensureBookingToken($call));

        if ($call->scheduled_call_at && $call->status === 'booked') {
            $meta = is_array($call->meta) ? $call->meta : [];
            if (!empty($meta['calendar_event_id']) && empty($meta['meeting_url'])) {
                $this->calendar->refreshMeetingLinkForCall($call->fresh(), $user);
                $call->refresh();
            }
        }

        return Inertia::render('crm/Calls/Show', [
            'call' => $this->serializeCall($call, true),
            'messages' => $messages,
            'latestAnalysis' => $latestAnalysis,
            'settings' => $settings,
            'bookingUrl' => $bookingUrl !== '' ? $bookingUrl : null,
            'hasUnipile' => (bool) V2IntegrationAccount::activeUnipileAccountId($user->id),
            'hasCalendarIntegration' => $this->calendar->isAvailable($user->id),
            'aiConfigured' => (bool) config('services.chatgpt.key'),
            'suggestedConversations' => $this->conversationOptionsForCall($call, $user->id),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $data = $request->validate([
            'calendar_url' => [
                Rule::requiredIf(fn () => $request->boolean('use_app_booking_link') === false),
                'nullable',
                'url',
                'max:500',
            ],
            'booking_message' => ['nullable', 'string', 'max:2000'],
            'auto_send_suggestions' => ['nullable', 'boolean'],
            'reminder_hours_before' => ['nullable', 'array'],
            'reminder_hours_before.*' => ['integer', 'min:1', 'max:168'],
            'calendar_id' => ['nullable', 'string', 'max:500'],
            'call_duration_minutes' => ['nullable', 'integer', 'min:15', 'max:240'],
            'use_unipile_calendar' => ['nullable', 'boolean'],
            'use_app_booking_link' => ['nullable', 'boolean'],
            'booking_days_ahead' => ['nullable', 'integer', 'min:1', 'max:30'],
            'booking_hours_start' => ['nullable', 'integer', 'min:0', 'max:23'],
            'booking_hours_end' => ['nullable', 'integer', 'min:1', 'max:24'],
            'calendar_timezone' => ['nullable', 'string', 'max:64'],
        ]);

        if ($request->boolean('use_app_booking_link', true)) {
            $data['calendar_url'] = '';
        }

        $this->orchestration->saveSettings($user, $data);

        return back()->with('success', 'Call settings saved.');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        /** @var User $user */
        $user = auth()->user();
        $orgId = (int) $user->current_organization_id;

        $call = V2Call::where('organization_id', $orgId)->where('id', $id)->firstOrFail();

        $data = $request->validate([
            'status' => ['nullable', 'string', 'max:50'],
            'pending_message' => ['nullable', 'string'],
            'scheduled_send_at' => ['nullable', 'date'],
            'scheduled_call_at' => ['nullable', 'date'],
            'prospect_name' => ['nullable', 'string', 'max:191'],
        ]);

        $call->forceFill(array_filter([
            'status' => $data['status'] ?? null,
            'pending_message' => array_key_exists('pending_message', $data) ? $data['pending_message'] : null,
            'scheduled_send_at' => $data['scheduled_send_at'] ?? null,
            'scheduled_call_at' => $data['scheduled_call_at'] ?? null,
            'prospect_name' => $data['prospect_name'] ?? null,
        ], fn ($v) => $v !== null))->save();

        if (!empty($data['scheduled_call_at'])) {
            $call->forceFill(['status' => 'booked'])->save();
            $this->orchestration->scheduleCallReminders($call->fresh(), $user);
        }

        return back()->with('success', !empty($data['scheduled_call_at'])
            ? 'Call updated — calendar event and reminders synced.'
            : 'Call updated.');
    }

    public function send(Request $request, int $id): RedirectResponse
    {
        /** @var User $user */
        $user = auth()->user();
        $orgId = (int) $user->current_organization_id;

        $call = V2Call::where('organization_id', $orgId)->where('id', $id)->firstOrFail();

        if ($request->filled('pending_message')) {
            $call->forceFill(['pending_message' => $request->string('pending_message')->toString()])->save();
            $call = $call->fresh();
        }

        if (!$call->conversation_id) {
            if (trim((string) $call->pending_message) === '') {
                return back()->with('error', 'Write a message before starting the chat.');
            }

            if (trim((string) $call->connection_id) === '') {
                return back()->with('error', 'This prospect is missing a LinkedIn profile ID.');
            }

            if (!V2IntegrationAccount::activeUnipileAccountId($user->id)) {
                return back()->with('error', 'Connect LinkedIn under Integrations before starting chats.');
            }

            LaunchCallFromLeadJob::dispatch($call->id);

            return back()->with('success', 'Sending your message… It will appear here once delivered.');
        }

        if (!$this->orchestration->sendPendingMessage($call)) {
            return back()->with('error', 'Could not send message.');
        }

        return back()->with('success', 'Message queued via Unipile.');
    }

    public function analyze(int $id): RedirectResponse
    {
        /** @var User $user */
        $user = auth()->user();
        $orgId = (int) $user->current_organization_id;

        $call = V2Call::where('organization_id', $orgId)->where('id', $id)->firstOrFail();

        try {
            $result = $this->orchestration->regenerateSuggestion($call, $user);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $source = (string) ($result['analysis']['source'] ?? 'heuristic');
        $notice = $source === 'openai'
            ? 'AI suggestion updated.'
            : 'Suggestion updated (using built-in rules — set a valid OPENAI_API_KEY in .env for full AI).';

        return back()->with('success', $notice);
    }

    public function dismiss(int $id): RedirectResponse
    {
        /** @var User $user */
        $user = auth()->user();
        $orgId = (int) $user->current_organization_id;

        $call = V2Call::where('organization_id', $orgId)->where('id', $id)->firstOrFail();
        $call->forceFill(['pending_message' => null, 'scheduled_send_at' => null])->save();

        return back()->with('success', 'Suggestion dismissed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCall(V2Call $call, bool $detailed = false): array
    {
        $data = [
            'id' => $call->id,
            'prospect_name' => $call->prospect_name,
            'prospect_headline' => $call->prospect_headline,
            'connection_id' => $call->connection_id,
            'conversation_id' => $call->conversation_id,
            'status' => $call->status,
            'pipeline_stage' => $this->orchestration->pipelineStage((string) $call->status),
            'pending_message' => $call->pending_message,
            'scheduled_send_at' => $call->scheduled_send_at?->toIso8601String(),
            'scheduled_call_at' => $call->scheduled_call_at?->toIso8601String(),
            'created_at' => $call->created_at?->toIso8601String(),
            'updated_at' => $call->updated_at?->toIso8601String(),
            'has_conversation' => (bool) $call->conversation_id,
            'launch_pending' => !$call->conversation_id && Arr::get(is_array($call->meta) ? $call->meta : [], 'launch_pending_at'),
            'launch_error_user' => OutreachUserErrorMapper::userMessageForCall(is_array($call->meta) ? $call->meta : null),
            'calendar_html_link' => Arr::get(is_array($call->meta) ? $call->meta : [], 'calendar_html_link'),
            'meeting_url' => Arr::get(is_array($call->meta) ? $call->meta : [], 'meeting_url'),
            'calendar_sync_error' => Arr::get(is_array($call->meta) ? $call->meta : [], 'calendar_sync_error'),
            'ready_to_send' => $call->pending_message
                && (!$call->scheduled_send_at || $call->scheduled_send_at->isPast()),
            'batch_id' => Arr::get(is_array($call->meta) ? $call->meta : [], 'batch_id'),
            'batch_name' => Arr::get(is_array($call->meta) ? $call->meta : [], 'batch_name'),
        ];

        if ($detailed) {
            $data['reminders'] = $call->relationLoaded('reminders')
                ? $call->reminders->map(fn (V2Reminder $r) => [
                    'id' => $r->id,
                    'message' => $r->message,
                    'send_at' => $r->send_at?->toIso8601String(),
                    'status' => $r->status,
                ])
                : [];
            $data['ai_analysis'] = $call->ai_analysis;
        }

        return $data;
    }

    /**
     * @return list<array{id: int, prospect_name: string|null, prospect_headline: string|null, provider_chat_id: string|null, last_message_at: string|null}>
     */
    private function conversationOptionsForCall(V2Call $call, int $userId): array
    {
        return V2Conversation::query()
            ->where('user_id', $userId)
            ->managedByCallManager()
            ->with([
                'lead:id,full_name,headline,provider_profile_id,public_identifier',
                'calls:id,conversation_id,prospect_name,prospect_headline,status',
            ])
            ->latest('last_message_at')
            ->limit(100)
            ->get()
            ->filter(fn (V2Conversation $conversation) => $this->conversationMatchesCall($conversation, $call))
            ->map(fn (V2Conversation $c) => $this->serializeConversationOption($c))
            ->values()
            ->all();
    }

    private function conversationMatchesCall(V2Conversation $conversation, V2Call $call): bool
    {
        $connectionId = trim((string) $call->connection_id);
        if ($connectionId !== '') {
            $meta = is_array($conversation->meta) ? $conversation->meta : [];
            $attendees = Arr::get($meta, 'attendee_ids', []);
            if (is_array($attendees) && in_array($connectionId, $attendees, true)) {
                return true;
            }

            if ($conversation->relationLoaded('lead') && $conversation->lead) {
                $providerId = trim((string) ($conversation->lead->provider_profile_id ?? ''));
                $publicId = trim((string) ($conversation->lead->public_identifier ?? ''));
                if ($providerId === $connectionId || $publicId === $connectionId) {
                    return true;
                }
            }
        }

        $callName = strtolower(trim((string) $call->prospect_name));
        if ($callName === '') {
            return false;
        }

        $option = $this->serializeConversationOption($conversation);
        $convName = strtolower(trim((string) ($option['prospect_name'] ?? '')));

        return $convName !== '' && $convName === $callName;
    }

    /**
     * @return list<array{id: int, prospect_name: string|null, prospect_headline: string|null, provider_chat_id: string|null, last_message_at: string|null}>
     */
    private function conversationOptionsForUser(int $userId): array
    {
        return V2Conversation::query()
            ->where('user_id', $userId)
            ->managedByCallManager()
            ->with([
                'lead:id,full_name,headline',
                'calls:id,conversation_id,prospect_name,prospect_headline',
            ])
            ->latest('last_message_at')
            ->limit(50)
            ->get()
            ->map(fn (V2Conversation $c) => $this->serializeConversationOption($c))
            ->values()
            ->all();
    }

    /**
     * @return array{id: int, prospect_name: string|null, prospect_headline: string|null, provider_chat_id: string|null, last_message_at: string|null}
     */
    private function serializeConversationOption(V2Conversation $conversation): array
    {
        $meta = is_array($conversation->meta) ? $conversation->meta : [];

        $prospectName = trim((string) Arr::get($meta, 'prospect_name', ''));
        $prospectHeadline = trim((string) Arr::get($meta, 'prospect_headline', ''));

        if ($prospectName === '' && $conversation->relationLoaded('lead') && $conversation->lead) {
            $prospectName = trim((string) ($conversation->lead->full_name ?? ''));
            if ($prospectHeadline === '') {
                $prospectHeadline = trim((string) ($conversation->lead->headline ?? ''));
            }
        }

        if ($conversation->relationLoaded('calls')) {
            $call = $conversation->calls->first();
            if ($call) {
                if ($prospectName === '') {
                    $prospectName = trim((string) ($call->prospect_name ?? ''));
                }
                if ($prospectHeadline === '') {
                    $prospectHeadline = trim((string) ($call->prospect_headline ?? ''));
                }
            }
        }

        return [
            'id' => $conversation->id,
            'prospect_name' => $prospectName !== '' ? $prospectName : null,
            'prospect_headline' => $prospectHeadline !== '' ? $prospectHeadline : null,
            'provider_chat_id' => $conversation->provider_chat_id,
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
        ];
    }
}

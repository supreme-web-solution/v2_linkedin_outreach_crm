<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\V2Call;
use App\Models\V2Conversation;
use App\Models\V2IntegrationAccount;
use App\Models\V2Message;
use App\Models\V2Reminder;
use App\V2\Services\CallOrchestrationService;
use App\V2\Services\LeadListService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CallsWebController extends Controller
{
    public function __construct(
        private readonly CallOrchestrationService $orchestration,
        private readonly LeadListService $leadLists,
    ) {
    }

    public function index(): Response
    {
        /** @var User $user */
        $user = auth()->user();
        $orgId = (int) $user->current_organization_id;

        $calls = $orgId
            ? V2Call::where('organization_id', $orgId)
                ->whereNotIn('status', ['completed', 'lost', 'failed'])
                ->latest('updated_at')
                ->limit(100)
                ->get()
                ->map(fn (V2Call $c) => $this->serializeCall($c))
            : collect();

        $pipeline = [
            'engaged' => $calls->filter(fn ($c) => $c['pipeline_stage'] === 'engaged')->values(),
            'scheduling' => $calls->filter(fn ($c) => $c['pipeline_stage'] === 'scheduling')->values(),
            'booked' => $calls->filter(fn ($c) => $c['pipeline_stage'] === 'booked')->values(),
        ];

        $upcoming = $orgId
            ? V2Call::where('organization_id', $orgId)
                ->where('status', 'booked')
                ->where('scheduled_call_at', '>=', now())
                ->orderBy('scheduled_call_at')
                ->limit(10)
                ->get()
                ->map(fn (V2Call $c) => $this->serializeCall($c))
            : collect();

        $dueReminders = $orgId
            ? V2Reminder::where('organization_id', $orgId)
                ->where('status', 'pending')
                ->where('send_at', '<=', now()->addHours(24))
                ->with('call')
                ->orderBy('send_at')
                ->limit(20)
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
            'upcoming' => $upcoming,
            'dueReminders' => $dueReminders,
            'stats' => $stats,
            'settings' => $this->orchestration->settingsFor($user),
            'hasOrg' => (bool) $orgId,
            'hasUnipile' => (bool) V2IntegrationAccount::activeUnipileAccountId($user->id),
            'leadLists' => $this->leadLists->listsForUser($user->id)->values()->all(),
            'conversations' => $orgId
                ? V2Conversation::where('user_id', $user->id)
                    ->managedByCallManager()
                    ->latest('last_message_at')
                    ->limit(50)
                    ->get()
                    ->map(fn (V2Conversation $c) => [
                        'id' => $c->id,
                        'provider_chat_id' => $c->provider_chat_id,
                        'last_message_at' => $c->last_message_at?->toIso8601String(),
                    ])
                : [],
        ]);
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
            'list_id' => ['required', 'string'],
            'src' => ['required', 'in:aud,sn'],
            'select_all' => ['nullable', 'boolean'],
            'lead_ids' => ['nullable', 'array'],
            'lead_ids.*' => ['integer'],
            'batch_name' => ['nullable', 'string', 'max:191'],
            'pending_message' => ['nullable', 'string'],
            'run' => ['nullable', 'boolean'],
        ]);

        $selectAll = (bool) ($data['select_all'] ?? false);
        $leadIds = array_map('intval', $data['lead_ids'] ?? []);

        if (!$selectAll && $leadIds === []) {
            return back()->with('error', 'Select at least one lead or choose select all.');
        }

        if (($data['run'] ?? false) && !V2IntegrationAccount::activeUnipileAccountId($user->id)) {
            return back()->with('error', 'Connect LinkedIn under Integrations before starting chats.');
        }

        $leads = $this->leadLists->resolveLeads($user->id, $data['list_id'], $data['src'], $leadIds, $selectAll);

        if ($leads->isEmpty()) {
            return back()->with('error', 'No leads found in that list.');
        }

        $result = $this->orchestration->createCallsFromLeads($user, $orgId, $leads, [
            'list_id' => $data['list_id'],
            'src' => $data['src'],
            'batch_name' => $data['batch_name'] ?? null,
            'pending_message' => $data['pending_message'] ?? null,
            'run' => (bool) ($data['run'] ?? false),
        ]);

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

        return Inertia::render('crm/Calls/Show', [
            'call' => $this->serializeCall($call, true),
            'messages' => $messages,
            'latestAnalysis' => $latestAnalysis,
            'settings' => $this->orchestration->settingsFor($user),
            'hasUnipile' => (bool) V2IntegrationAccount::activeUnipileAccountId($user->id),
            'aiConfigured' => (bool) config('services.chatgpt.key'),
            'conversations' => V2Conversation::where('user_id', $user->id)
                ->managedByCallManager()
                ->latest('last_message_at')
                ->limit(50)
                ->get()
                ->map(fn (V2Conversation $c) => [
                    'id' => $c->id,
                    'provider_chat_id' => $c->provider_chat_id,
                    'last_message_at' => $c->last_message_at?->toIso8601String(),
                ]),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $data = $request->validate([
            'calendar_url' => ['nullable', 'url', 'max:500'],
            'booking_message' => ['nullable', 'string', 'max:2000'],
            'auto_send_suggestions' => ['nullable', 'boolean'],
            'reminder_hours_before' => ['nullable', 'array'],
            'reminder_hours_before.*' => ['integer', 'min:1', 'max:168'],
        ]);

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

        return back()->with('success', 'Call updated.');
    }

    public function send(Request $request, int $id): RedirectResponse
    {
        /** @var User $user */
        $user = auth()->user();
        $orgId = (int) $user->current_organization_id;

        $call = V2Call::where('organization_id', $orgId)->where('id', $id)->firstOrFail();

        if ($request->filled('pending_message')) {
            $call->forceFill(['pending_message' => $request->string('pending_message')->toString()])->save();
        }

        if (!$this->orchestration->sendPendingMessage($call->fresh())) {
            return back()->with('error', 'Could not send — launch outreach from Call Manager first so a conversation thread exists.');
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
            'ready_to_send' => $call->pending_message
                && (!$call->scheduled_send_at || $call->scheduled_send_at->isPast()),
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
}

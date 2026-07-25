<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\V2Call;
use App\Models\V2Conversation;
use App\Models\V2IntegrationAccount;
use App\Models\V2Message;
use App\V2\Services\CallOrchestrationService;
use App\V2\Services\ConversationMessagingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

class ConversationsWebController extends Controller
{
    public function __construct(
        private readonly ConversationMessagingService $messaging,
        private readonly CallOrchestrationService $callOrchestration,
    ) {
    }

    public function index(Request $request): Response|RedirectResponse
    {
        /** @var User $user */
        $user = auth()->user();
        $orgId = (int) $user->current_organization_id;

        $legacyFlow = trim((string) $request->query('flow', ''));
        $legacyStage = trim((string) $request->query('stage', ''));

        if ($legacyFlow !== '' && $legacyFlow !== '__all__') {
            $flowKey = $legacyFlow === '__individual__' ? 'individual' : $legacyFlow;

            return redirect()->route('conversations.flow', array_filter([
                'flowKey' => $flowKey,
                'stage' => $legacyStage !== '' && $legacyStage !== 'all' ? $legacyStage : null,
            ]));
        }

        if ($legacyStage !== '' && $legacyStage !== 'all') {
            return redirect()->route('conversations.flow', [
                'flowKey' => 'all',
                'stage' => $legacyStage,
            ]);
        }

        $flows = $orgId ? $this->callOrchestration->flowsForOrganization($orgId) : [];
        $totalProspects = array_sum(array_column($flows, 'count'));
        $totalReady = array_sum(array_column($flows, 'ready_to_send'));

        return Inertia::render('crm/Conversations/Index', [
            'flows' => $flows,
            'stats' => [
                'flow_count' => count($flows),
                'prospect_count' => $totalProspects,
                'ready_to_send' => $totalReady,
            ],
            'hasUnipile' => (bool) V2IntegrationAccount::activeUnipileAccountId($user->id),
            'hasOrg' => (bool) $orgId,
        ]);
    }

    public function flow(Request $request, string $flowKey): Response
    {
        /** @var User $user */
        $user = auth()->user();
        $orgId = (int) $user->current_organization_id;
        $hasUnipile = (bool) V2IntegrationAccount::activeUnipileAccountId($user->id);

        $search = trim((string) $request->query('search', ''));
        $status = trim((string) $request->query('status', ''));
        $stage = trim((string) $request->query('stage', ''));

        $flowContext = $this->resolveFlowContext($orgId, $flowKey);

        $query = V2Call::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $orgId)
            ->whereNotIn('status', ['completed', 'lost', 'failed'])
            ->with(['conversation' => fn ($convQuery) => $convQuery->withCount('messages')]);

        $this->applyFlowScopeToCalls($query, $flowKey);
        $this->applyStageScopeToCalls($query, $stage);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('prospect_name', 'like', '%'.$search.'%')
                    ->orWhere('prospect_headline', 'like', '%'.$search.'%')
                    ->orWhere('connection_id', 'like', '%'.$search.'%')
                    ->orWhereHas('conversation', function ($convQuery) use ($search) {
                        $convQuery->where('provider_chat_id', 'like', '%'.$search.'%')
                            ->orWhere('meta', 'like', '%'.$search.'%')
                            ->orWhereHas('lead', function ($leadQuery) use ($search) {
                                $leadQuery->where('full_name', 'like', '%'.$search.'%')
                                    ->orWhere('public_identifier', 'like', '%'.$search.'%');
                            });
                    });
            });
        }

        if ($status !== '' && $status !== 'all') {
            $query->whereHas('conversation', fn ($convQuery) => $convQuery->where('status', $status));
        }

        $prospects = $query
            ->orderByRaw('conversation_id IS NULL DESC')
            ->orderByDesc('updated_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (V2Call $call) => $this->serializeFlowProspect($call));

        $batchId = $flowContext['batch_id'];
        $flowSettings = $orgId && !$flowContext['is_aggregate']
            ? $this->callOrchestration->flowSnapshotForBatch($orgId, $batchId)
            : null;

        return Inertia::render('crm/Conversations/Flow', [
            'prospects' => $prospects,
            'hasUnipile' => $hasUnipile,
            'flow' => $flowContext,
            'flowSettings' => $flowSettings,
            'filters' => [
                'search' => $search !== '' ? $search : null,
                'status' => $status !== '' ? $status : null,
                'stage' => $stage !== '' ? $stage : null,
            ],
        ]);
    }

    public function show(int $id): RedirectResponse
    {
        /** @var User $user */
        $user = auth()->user();

        V2Conversation::where('user_id', $user->id)
            ->managedByCallManager()
            ->where('id', $id)
            ->firstOrFail();

        $callId = V2Call::query()
            ->where('user_id', $user->id)
            ->where('conversation_id', $id)
            ->latest('id')
            ->value('id');

        if ($callId) {
            return redirect()->route('calls.show', $callId);
        }

        return redirect()->route('calls')
            ->with('error', 'Open this thread from Call Manager — no linked call was found.');
    }

    public function send(Request $request, int $id): RedirectResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $conversation = V2Conversation::where('user_id', $user->id)
            ->managedByCallManager()
            ->where('id', $id)
            ->firstOrFail();

        $data = $request->validate([
            'body' => ['required', 'string', 'max:8000'],
        ]);

        try {
            $this->messaging->sendMessage($user, $conversation, $data['body']);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        $callId = V2Call::query()
            ->where('user_id', $user->id)
            ->where('conversation_id', $conversation->id)
            ->latest('id')
            ->value('id');

        if ($callId) {
            return redirect()->route('calls.show', $callId)
                ->with('success', 'Message queued via Unipile.');
        }

        return back()->with('success', 'Message queued via Unipile.');
    }

    public function trackCall(int $id): RedirectResponse
    {
        /** @var User $user */
        $user = auth()->user();
        $orgId = (int) $user->current_organization_id;

        if (!$orgId) {
            return back()->with('error', 'Connect your workspace first.');
        }

        $conversation = V2Conversation::where('user_id', $user->id)
            ->managedByCallManager()
            ->where('id', $id)
            ->firstOrFail();

        $call = $this->callOrchestration->createCall($user, $orgId, [
            'conversation_id' => $conversation->id,
            'prospect_name' => $this->prospectName($conversation),
        ]);

        return redirect()->route('calls.show', $call->id)
            ->with('success', 'This conversation is now tracked in Call Manager.');
    }

    public function destroy(int $id): RedirectResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $conversation = V2Conversation::query()
            ->where('user_id', $user->id)
            ->managedByCallManager()
            ->where('id', $id)
            ->firstOrFail();

        $calls = V2Call::query()
            ->where('user_id', $user->id)
            ->where('conversation_id', $conversation->id)
            ->whereNotIn('status', ['completed', 'lost', 'failed'])
            ->get();

        if ($calls->isNotEmpty()) {
            $this->deleteProspectCalls($user, $calls);
        } else {
            $conversation->delete();
        }

        return redirect()->route('conversations')->with('success', 'Conversation removed from CRM.');
    }

    public function destroyProspect(int $callId): RedirectResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $call = V2Call::query()
            ->where('user_id', $user->id)
            ->whereNotIn('status', ['completed', 'lost', 'failed'])
            ->findOrFail($callId);

        $this->deleteProspectCalls($user, collect([$call]));

        return back()->with('success', 'Prospect and chat removed.');
    }

    public function destroyFlow(string $flowKey): RedirectResponse
    {
        if ($flowKey === 'all') {
            abort(404);
        }

        /** @var User $user */
        $user = auth()->user();
        $orgId = (int) $user->current_organization_id;

        if (!$orgId) {
            return redirect()->route('conversations')->with('error', 'Connect your workspace first.');
        }

        $this->resolveFlowContext($orgId, $flowKey);

        $query = V2Call::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $orgId)
            ->whereNotIn('status', ['completed', 'lost', 'failed']);

        $this->applyFlowScopeToCalls($query, $flowKey);

        $calls = $query->get();
        if ($calls->isEmpty()) {
            return redirect()->route('conversations')->with('error', 'No prospects to delete in this flow.');
        }

        $count = $calls->count();
        $this->deleteProspectCalls($user, $calls);

        return redirect()->route('conversations')->with('success', "{$count} prospect(s) and their chats removed.");
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $deleted = 0;

        $conversations = V2Conversation::query()
            ->where('user_id', $user->id)
            ->managedByCallManager()
            ->whereIn('id', $data['ids'])
            ->get();

        foreach ($conversations as $conversation) {
            $calls = V2Call::query()
                ->where('user_id', $user->id)
                ->where('conversation_id', $conversation->id)
                ->whereNotIn('status', ['completed', 'lost', 'failed'])
                ->get();

            if ($calls->isNotEmpty()) {
                $this->deleteProspectCalls($user, $calls);
            } else {
                $conversation->delete();
            }

            $deleted++;
        }

        if ($deleted === 0) {
            return back()->with('error', 'No conversations were deleted.');
        }

        return back()->with('success', "{$deleted} conversation(s) removed from CRM.");
    }

    /**
     * @return array{
     *     flow_key: string,
     *     batch_id: string|null,
     *     batch_name: string,
     *     is_aggregate: bool,
     *     count: int,
     *     chats_started: int,
     *     ready_to_send: int,
     *     auto_send_enabled: bool,
     *     stages: array{engaged: int, scheduling: int, booked: int}
     * }
     */
    private function resolveFlowContext(int $orgId, string $flowKey): array
    {
        if ($flowKey === 'all') {
            $flows = $orgId ? $this->callOrchestration->flowsForOrganization($orgId) : [];

            return [
                'flow_key' => 'all',
                'batch_id' => null,
                'batch_name' => 'All prospects',
                'is_aggregate' => true,
                'count' => array_sum(array_column($flows, 'count')),
                'chats_started' => array_sum(array_column($flows, 'chats_started')),
                'ready_to_send' => array_sum(array_column($flows, 'ready_to_send')),
                'auto_send_enabled' => false,
                'stages' => [
                    'engaged' => array_sum(array_column(array_column($flows, 'stages'), 'engaged')),
                    'scheduling' => array_sum(array_column(array_column($flows, 'stages'), 'scheduling')),
                    'booked' => array_sum(array_column(array_column($flows, 'stages'), 'booked')),
                ],
            ];
        }

        if ($flowKey === 'individual') {
            $flows = $orgId ? $this->callOrchestration->flowsForOrganization($orgId) : [];
            $match = collect($flows)->firstWhere('flow_key', 'individual');

            return [
                'flow_key' => 'individual',
                'batch_id' => null,
                'batch_name' => 'Individual prospects',
                'is_aggregate' => false,
                'count' => (int) ($match['count'] ?? 0),
                'chats_started' => (int) ($match['chats_started'] ?? 0),
                'ready_to_send' => (int) ($match['ready_to_send'] ?? 0),
                'auto_send_enabled' => (bool) ($match['auto_send_enabled'] ?? false),
                'stages' => $match['stages'] ?? ['engaged' => 0, 'scheduling' => 0, 'booked' => 0],
            ];
        }

        $flows = $orgId ? $this->callOrchestration->flowsForOrganization($orgId) : [];
        $match = collect($flows)->firstWhere('flow_key', $flowKey);

        if (!$match) {
            abort(404);
        }

        return [
            'flow_key' => $flowKey,
            'batch_id' => $match['batch_id'],
            'batch_name' => $match['batch_name'],
            'is_aggregate' => false,
            'count' => $match['count'],
            'chats_started' => (int) ($match['chats_started'] ?? 0),
            'ready_to_send' => $match['ready_to_send'],
            'auto_send_enabled' => $match['auto_send_enabled'],
            'stages' => $match['stages'],
        ];
    }

    /**
     * @param  Builder<V2Call>  $query
     */
    private function applyFlowScopeToCalls(Builder $query, string $flowKey): void
    {
        if ($flowKey === 'all') {
            return;
        }

        if ($flowKey === 'individual') {
            $query->where(function ($batchQuery) {
                $batchQuery->whereNull('meta->batch_id')
                    ->orWhere('meta->batch_id', '');
            });

            return;
        }

        $query->where('meta->batch_id', $flowKey);
    }

    /**
     * @param  Builder<V2Call>  $query
     */
    private function applyStageScopeToCalls(Builder $query, string $stage): void
    {
        if ($stage === '' || $stage === 'all') {
            return;
        }

        match ($stage) {
            'scheduling' => $query->whereIn('status', ['scheduling', 'sent', 'in_progress']),
            'booked' => $query->where('status', 'booked'),
            default => $query->whereNotIn('status', ['scheduling', 'sent', 'in_progress', 'booked']),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeFlowProspect(V2Call $call): array
    {
        $conversation = $call->conversation;
        $pipelineStage = $this->callOrchestration->pipelineStage((string) $call->status);
        $readyToSend = $call->pending_message
            && (!$call->scheduled_send_at || $call->scheduled_send_at->isPast());

        $lastMessage = null;
        if ($conversation) {
            $lastMessage = V2Message::query()
                ->where('conversation_id', $conversation->id)
                ->whereNotNull('body')
                ->where('body', '!=', '')
                ->orderByDesc('received_at')
                ->orderByDesc('sent_at')
                ->orderByDesc('created_at')
                ->value('body');
        }

        if (!$lastMessage && $readyToSend && $call->pending_message) {
            $lastMessage = $call->pending_message;
        }

        return [
            'call_id' => $call->id,
            'conversation_id' => $call->conversation_id ? (int) $call->conversation_id : null,
            'chat_started' => (bool) $call->conversation_id,
            'prospect_name' => $call->prospect_name,
            'prospect_headline' => $call->prospect_headline,
            'pipeline_stage' => $pipelineStage,
            'ready_to_send' => $readyToSend,
            'pending_message_preview' => $call->pending_message ? mb_substr((string) $call->pending_message, 0, 120) : null,
            'last_message_preview' => $lastMessage ? mb_substr((string) $lastMessage, 0, 120) : null,
            'messages_count' => (int) ($conversation?->messages_count ?? 0),
            'conversation_status' => $conversation?->status,
            'last_message_at' => $conversation?->last_message_at?->toIso8601String(),
            'updated_at' => $call->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  Builder<V2Conversation>  $query
     */
    private function applyFlowScope(Builder $query, User $user, string $flowKey): void
    {
        if ($flowKey === 'all') {
            return;
        }

        if ($flowKey === 'individual') {
            $query->whereHas('calls', function ($callQuery) use ($user) {
                $callQuery->where('user_id', $user->id)
                    ->where(function ($batchQuery) {
                        $batchQuery->whereNull('meta->batch_id')
                            ->orWhere('meta->batch_id', '');
                    });
            });

            return;
        }

        $query->whereHas('calls', function ($callQuery) use ($user, $flowKey) {
            $callQuery->where('user_id', $user->id)
                ->where('meta->batch_id', $flowKey);
        });
    }

    /**
     * @param  Builder<V2Conversation>  $query
     */
    private function applyStageScope(Builder $query, User $user, string $stage): void
    {
        if ($stage === '' || $stage === 'all') {
            return;
        }

        $query->whereHas('calls', function ($callQuery) use ($user, $stage) {
            $callQuery->where('user_id', $user->id)
                ->whereNotIn('status', ['completed', 'lost', 'failed']);
            match ($stage) {
                'scheduling' => $callQuery->whereIn('status', ['scheduling', 'sent', 'in_progress']),
                'booked' => $callQuery->where('status', 'booked'),
                default => $callQuery->whereNotIn('status', ['scheduling', 'sent', 'in_progress', 'booked']),
            };
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeConversation(V2Conversation $conversation): array
    {
        $call = V2Call::query()
            ->where('user_id', $conversation->user_id)
            ->where('conversation_id', $conversation->id)
            ->whereNotIn('status', ['completed', 'lost', 'failed'])
            ->latest('updated_at')
            ->first();

        if (!$call) {
            $call = V2Call::query()
                ->where('user_id', $conversation->user_id)
                ->where('conversation_id', $conversation->id)
                ->latest('id')
                ->first();
        }

        $lastMessage = V2Message::query()
            ->where('conversation_id', $conversation->id)
            ->whereNotNull('body')
            ->where('body', '!=', '')
            ->orderByDesc('received_at')
            ->orderByDesc('sent_at')
            ->orderByDesc('created_at')
            ->value('body');

        if (!$lastMessage) {
            $history = is_array($call?->conversation_history) ? $call->conversation_history : [];
            for ($i = count($history) - 1; $i >= 0; $i--) {
                $preview = trim((string) ($history[$i]['message'] ?? ''));
                if ($preview !== '') {
                    $lastMessage = $preview;
                    break;
                }
            }
        }

        $callMeta = is_array($call?->meta) ? $call->meta : [];
        $batchId = Arr::get($callMeta, 'batch_id');
        $batchName = $batchId
            ? (string) (Arr::get($callMeta, 'batch_name') ?: 'Untitled flow')
            : ($call ? 'Individual' : null);

        $pipelineStage = $call
            ? $this->callOrchestration->pipelineStage((string) $call->status)
            : null;

        $readyToSend = $call
            && $call->pending_message
            && (!$call->scheduled_send_at || $call->scheduled_send_at->isPast());

        return [
            'id' => $conversation->id,
            'call_id' => $call?->id ? (int) $call->id : null,
            'provider' => $conversation->provider,
            'provider_chat_id' => $conversation->provider_chat_id,
            'prospect_name' => $this->prospectName($conversation),
            'prospect_headline' => $conversation->lead?->headline
                ?? Arr::get($conversation->meta ?? [], 'prospect_headline'),
            'status' => $conversation->status,
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'created_at' => $conversation->created_at?->toIso8601String(),
            'messages_count' => (int) ($conversation->messages_count ?? $conversation->messages()->count()),
            'last_message_preview' => $lastMessage ? mb_substr((string) $lastMessage, 0, 120) : null,
            'has_chat_link' => (bool) $conversation->provider_chat_id,
            'batch_id' => $batchId ? (string) $batchId : null,
            'batch_name' => $batchName,
            'pipeline_stage' => $pipelineStage,
            'ready_to_send' => $readyToSend,
        ];
    }

    private function prospectName(V2Conversation $conversation): ?string
    {
        $fromLead = trim((string) ($conversation->lead?->full_name ?? ''));
        if ($fromLead !== '') {
            return $fromLead;
        }

        $fromMeta = trim((string) Arr::get($conversation->meta ?? [], 'prospect_name', ''));
        if ($fromMeta !== '') {
            return $fromMeta;
        }

        $attendees = Arr::get($conversation->meta ?? [], 'attendee_ids', []);
        if (is_array($attendees) && isset($attendees[0]) && is_string($attendees[0])) {
            return $attendees[0];
        }

        return null;
    }

    /**
     * @param  iterable<int, V2Call>  $calls
     */
    private function deleteProspectCalls(User $user, iterable $calls): void
    {
        $conversationIds = [];

        foreach ($calls as $call) {
            if ((int) $call->user_id !== (int) $user->id) {
                abort(404);
            }

            if ($call->conversation_id) {
                $conversationIds[] = (int) $call->conversation_id;
            }

            $call->delete();
        }

        if ($conversationIds === []) {
            return;
        }

        V2Conversation::query()
            ->where('user_id', $user->id)
            ->managedByCallManager()
            ->whereIn('id', array_values(array_unique($conversationIds)))
            ->delete();
    }
}

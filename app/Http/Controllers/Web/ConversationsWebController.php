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

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = auth()->user();
        $hasUnipile = (bool) V2IntegrationAccount::activeUnipileAccountId($user->id);

        $search = trim((string) $request->query('search', ''));
        $status = trim((string) $request->query('status', ''));

        $query = V2Conversation::query()
            ->where('user_id', $user->id)
            ->managedByCallManager()
            ->with(['lead'])
            ->withCount('messages');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('provider_chat_id', 'like', '%'.$search.'%')
                    ->orWhere('meta', 'like', '%'.$search.'%')
                    ->orWhereHas('lead', function ($leadQuery) use ($search) {
                        $leadQuery->where('full_name', 'like', '%'.$search.'%')
                            ->orWhere('public_identifier', 'like', '%'.$search.'%')
                            ->orWhere('provider_profile_id', 'like', '%'.$search.'%');
                    });
            });
        }

        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        $conversations = $query
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (V2Conversation $conversation) => $this->serializeConversation($conversation));

        return Inertia::render('crm/Conversations', [
            'conversations' => $conversations,
            'hasUnipile' => $hasUnipile,
            'filters' => [
                'search' => $search !== '' ? $search : null,
                'status' => $status !== '' ? $status : null,
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
            ->where('id', $id)
            ->firstOrFail();

        $conversation->delete();

        return redirect()->route('conversations')->with('success', 'Conversation removed from CRM.');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $deleted = V2Conversation::query()
            ->where('user_id', $user->id)
            ->whereIn('id', $data['ids'])
            ->delete();

        if ($deleted === 0) {
            return back()->with('error', 'No conversations were deleted.');
        }

        return back()->with('success', "{$deleted} conversation(s) removed from CRM.");
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeConversation(V2Conversation $conversation): array
    {
        $lastMessage = V2Message::query()
            ->where('conversation_id', $conversation->id)
            ->whereNotNull('body')
            ->where('body', '!=', '')
            ->orderByDesc('received_at')
            ->orderByDesc('sent_at')
            ->orderByDesc('created_at')
            ->value('body');

        if (!$lastMessage) {
            $call = V2Call::query()
                ->where('user_id', $conversation->user_id)
                ->where('conversation_id', $conversation->id)
                ->latest('id')
                ->first();

            $history = is_array($call?->conversation_history) ? $call->conversation_history : [];
            for ($i = count($history) - 1; $i >= 0; $i--) {
                $preview = trim((string) ($history[$i]['message'] ?? ''));
                if ($preview !== '') {
                    $lastMessage = $preview;
                    break;
                }
            }
        }

        $callId = V2Call::query()
            ->where('user_id', $conversation->user_id)
            ->where('conversation_id', $conversation->id)
            ->latest('id')
            ->value('id');

        return [
            'id' => $conversation->id,
            'call_id' => $callId ? (int) $callId : null,
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
}

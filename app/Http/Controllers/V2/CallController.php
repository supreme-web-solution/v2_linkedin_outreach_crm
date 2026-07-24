<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Models\V2Call;
use App\V2\Services\CallOrchestrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CallController extends Controller
{
    public function __construct(private readonly CallOrchestrationService $callOrchestration)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');
        $data = $request->validate([
            'conversation_id' => ['nullable', 'integer'],
            'connection_id' => ['nullable', 'string', 'max:191'],
            'pending_message' => ['nullable', 'string'],
            'scheduled_send_at' => ['nullable', 'date'],
            'scheduled_call_at' => ['nullable', 'date'],
            'meta' => ['nullable', 'array'],
        ]);

        $call = V2Call::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organizationId,
            'conversation_id' => $data['conversation_id'] ?? null,
            'connection_id' => $data['connection_id'] ?? null,
            'pending_message' => $data['pending_message'] ?? null,
            'scheduled_send_at' => $data['scheduled_send_at'] ?? null,
            'scheduled_call_at' => $data['scheduled_call_at'] ?? null,
            'status' => 'pending',
            'conversation_history' => [],
            'ai_analysis' => [],
            'meta' => $data['meta'] ?? [],
        ]);

        return response()->json(['data' => $call], 201);
    }

    public function status(Request $request, int $id): JsonResponse
    {
        $call = $this->resolveOwnedCall($request, $id);
        if (!$call) {
            return response()->json(['message' => 'Call not found.'], 404);
        }

        return response()->json(['data' => $call]);
    }

    public function conversation(Request $request, int $id): JsonResponse
    {
        $call = $this->resolveOwnedCall($request, $id);
        if (!$call) {
            return response()->json(['message' => 'Call not found.'], 404);
        }

        return response()->json([
            'call_id' => $call->id,
            'conversation_history' => $call->conversation_history ?? [],
        ]);
    }

    public function storeConversationMessage(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');
        $data = $request->validate([
            'call_id' => ['required', 'integer'],
            'sender' => ['required', 'string', 'max:50'],
            'message' => ['required', 'string'],
        ]);

        $call = V2Call::query()
            ->where('id', $data['call_id'])
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->first();

        if (!$call) {
            return response()->json(['message' => 'Call not found.'], 404);
        }

        $history = is_array($call->conversation_history) ? $call->conversation_history : [];
        $history[] = [
            'sender' => $data['sender'],
            'message' => $data['message'],
            'at' => now()->toIso8601String(),
        ];

        $call->forceFill([
            'conversation_history' => $history,
            'status' => 'in_progress',
        ])->save();

        return response()->json(['data' => $call]);
    }

    public function analyzeMessage(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');
        $data = $request->validate([
            'call_id' => ['required', 'integer'],
            'message' => ['required', 'string'],
        ]);

        $call = V2Call::query()
            ->where('id', $data['call_id'])
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->first();

        if (!$call) {
            return response()->json(['message' => 'Call not found.'], 404);
        }

        $analysis = $this->callOrchestration->analyzeMessage($data['message']);

        $existing = is_array($call->ai_analysis) ? $call->ai_analysis : [];
        $existing[] = $analysis;
        $call->forceFill(['ai_analysis' => $existing])->save();

        return response()->json(['data' => $analysis]);
    }

    public function processReply(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');
        $data = $request->validate([
            'call_id' => ['required', 'integer'],
            'message' => ['required', 'string'],
            'sender' => ['nullable', 'string', 'max:50'],
        ]);

        $call = V2Call::query()
            ->where('id', $data['call_id'])
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->first();

        if (!$call) {
            return response()->json(['message' => 'Call not found.'], 404);
        }

        $sender = $data['sender'] ?? 'prospect';
        $result = $this->callOrchestration->handleInboundReply($call, $data['message'], $user, $sender);

        return response()->json(['data' => $result]);
    }

    public function generateMessage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'intent' => ['nullable', 'string', 'max:50'],
            'context' => ['nullable', 'string'],
        ]);

        $intent = $data['intent'] ?? 'neutral';
        $reply = $this->callOrchestration->nextReplyForIntent($intent);

        if (!empty($data['context'])) {
            $reply .= ' Context considered: '.$data['context'];
        }

        return response()->json([
            'data' => [
                'message' => $reply,
                'intent' => $intent,
            ],
        ]);
    }

    public function schedulingInfo(Request $request, int $id): JsonResponse
    {
        $call = $this->resolveOwnedCall($request, $id);
        if (!$call) {
            return response()->json(['message' => 'Call not found.'], 404);
        }

        return response()->json([
            'data' => [
                'call_id' => $call->id,
                'scheduled_call_at' => $call->scheduled_call_at,
                'scheduled_send_at' => $call->scheduled_send_at,
                'status' => $call->status,
            ],
        ]);
    }

    public function searchByConnection(Request $request, string $connectionId): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');

        $call = V2Call::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->where('connection_id', $connectionId)
            ->latest('id')
            ->first();

        if (!$call) {
            return response()->json(['message' => 'No call found for this connection.'], 404);
        }

        return response()->json(['data' => $call]);
    }

    public function readyToSend(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');

        $calls = V2Call::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->whereIn('status', ['pending', 'engaged', 'scheduling', 'in_progress'])
            ->whereNotNull('pending_message')
            ->where(function ($query) {
                $query->whereNull('scheduled_send_at')
                    ->orWhere('scheduled_send_at', '<=', now());
            })
            ->limit(20)
            ->get();

        return response()->json(['data' => $calls]);
    }

    public function updateMessageStatus(Request $request, int $id): JsonResponse
    {
        $call = $this->resolveOwnedCall($request, $id);
        if (!$call) {
            return response()->json(['message' => 'Call not found.'], 404);
        }

        $data = $request->validate([
            'status' => ['required', 'string', 'max:50'],
        ]);

        $call->forceFill(['status' => $data['status']])->save();

        return response()->json(['data' => $call]);
    }

    public function updatePendingMessage(Request $request, int $id): JsonResponse
    {
        $call = $this->resolveOwnedCall($request, $id);
        if (!$call) {
            return response()->json(['message' => 'Call not found.'], 404);
        }

        $data = $request->validate([
            'pending_message' => ['required', 'string'],
            'scheduled_send_at' => ['nullable', 'date'],
        ]);

        $call->forceFill([
            'pending_message' => $data['pending_message'],
            'scheduled_send_at' => $data['scheduled_send_at'] ?? null,
        ])->save();

        return response()->json(['data' => $call]);
    }

    private function resolveOwnedCall(Request $request, int $id): ?V2Call
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');

        return V2Call::query()
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->first();
    }
}

<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Models\V2Conversation;
use App\Models\V2Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');

        $conversations = V2Conversation::query()
            ->where('user_id', $user->id)
            ->latest('last_message_at')
            ->latest('id')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $conversations,
            'meta' => [
                'organization_id' => $organizationId,
            ],
        ]);
    }

    public function messages(Request $request, int $conversationId): JsonResponse
    {
        $user = $request->attributes->get('v2User');

        $conversation = V2Conversation::query()
            ->where('id', $conversationId)
            ->where('user_id', $user->id)
            ->first();

        if (!$conversation) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        $messages = V2Message::query()
            ->where('conversation_id', $conversation->id)
            ->latest('id')
            ->limit(200)
            ->get();

        return response()->json([
            'data' => $messages,
            'conversation' => $conversation,
        ]);
    }
}

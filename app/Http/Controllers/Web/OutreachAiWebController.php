<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\V2\Services\OpenAIContentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OutreachAiWebController extends Controller
{
    public function generateContent(Request $request, OpenAIContentService $openai): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', 'in:generate,paraphrase'],
            'channel' => ['nullable', 'string', 'max:50'],
            'action' => ['nullable', 'string', 'max:50'],
            'field' => ['required', 'in:message,body,subject'],
            'context' => ['nullable', 'string', 'max:2000'],
            'current_text' => ['nullable', 'string', 'max:5000'],
            'email_subject' => ['nullable', 'string', 'max:500'],
        ]);

        if ($data['mode'] === 'paraphrase' && trim((string) ($data['current_text'] ?? '')) === '') {
            return response()->json(['message' => 'Add some text first, then paraphrase.'], 422);
        }

        if ($data['mode'] === 'generate' && trim((string) ($data['context'] ?? '')) === '' && trim((string) ($data['current_text'] ?? '')) === '') {
            return response()->json(['message' => 'Describe what you want the message to say.'], 422);
        }

        try {
            $content = $openai->generateOutreachContent(
                $data['mode'],
                $data['channel'] ?? 'linkedin',
                $data['action'] ?? 'send_message',
                $data['field'],
                trim((string) ($data['context'] ?? '')),
                trim((string) ($data['current_text'] ?? '')),
                trim((string) ($data['email_subject'] ?? '')),
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'content' => $content,
        ]);
    }
}

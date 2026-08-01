<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\V2\Services\OpenAIContentService;
use App\V2\Services\OpenAiUserError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PostCommentController extends Controller
{
    public function __construct(
        private readonly OpenAIContentService $openai,
    ) {}

    /**
     * AI-generate a LinkedIn comment for a feed post (extension floating button).
     */
    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'post_content' => ['required', 'string', 'max:5000'],
            'tone' => ['nullable', 'string', 'in:professional,casual,engaging,thoughtful'],
        ]);

        $tone = $data['tone'] ?? 'professional';
        $postContent = trim($data['post_content']);

        if ($postContent === '') {
            return response()->json(['message' => 'Post content is empty.'], 422);
        }

        try {
            $comment = $this->openai->generateLinkedInComment($postContent, $tone);

            return response()->json([
                'data' => [
                    'comment' => $comment,
                    'word_count' => str_word_count($comment),
                ],
            ]);
        } catch (\Throwable $e) {
            $message = OpenAiUserError::fromThrowable($e);
            $status = $message === OpenAiUserError::BUSY ? 429 : 422;

            return response()->json(['message' => $message], $status);
        }
    }
}

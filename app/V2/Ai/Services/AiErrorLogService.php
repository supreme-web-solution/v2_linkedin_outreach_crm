<?php

namespace App\V2\Ai\Services;

use App\Models\AiConversation;
use App\Models\AiErrorLog;
use App\Models\User;
use Illuminate\Support\Str;
use Throwable;

class AiErrorLogService
{
    /**
     * Persist what Alex was doing when an error happened (admin diagnostics).
     *
     * @param  array<string, mixed>  $context
     */
    public function capture(
        Throwable $e,
        string $source,
        ?User $user = null,
        ?int $organizationId = null,
        ?AiConversation $conversation = null,
        ?string $channel = null,
        ?string $userMessage = null,
        array $context = [],
    ): AiErrorLog {
        return AiErrorLog::query()->create([
            'organization_id' => $organizationId,
            'user_id' => $user?->id,
            'conversation_id' => $conversation?->id,
            'source' => Str::limit($source, 64, ''),
            'channel' => $channel !== null ? Str::limit($channel, 32, '') : null,
            'exception_class' => Str::limit($e::class, 255, ''),
            'message' => Str::limit($e->getMessage() !== '' ? $e->getMessage() : $e::class, 5000, '…'),
            'user_message' => $userMessage !== null
                ? Str::limit($userMessage, 4000, '…')
                : null,
            'context' => $context !== [] ? $context : null,
            'trace' => Str::limit($e->getTraceAsString(), 20000, '…'),
        ]);
    }
}

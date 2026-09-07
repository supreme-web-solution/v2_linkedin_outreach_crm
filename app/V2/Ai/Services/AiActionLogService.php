<?php

namespace App\V2\Ai\Services;

use App\Models\AiActionLog;
use App\Models\AiConversation;
use App\Models\User;
use App\V2\Ai\Enums\AiToolPermission;

class AiActionLogService
{
    public function log(
        User $user,
        int $organizationId,
        string $tool,
        AiToolPermission $permission,
        string $status,
        ?AiConversation $conversation = null,
        ?array $input = null,
        ?array $output = null,
        ?string $error = null,
        ?int $durationMs = null,
    ): AiActionLog {
        return AiActionLog::query()->create([
            'organization_id' => $organizationId,
            'user_id' => $user->id,
            'conversation_id' => $conversation?->id,
            'tool' => $tool,
            'permission' => $permission->value,
            'status' => $status,
            'input' => $input,
            'output' => $output,
            'error' => $error,
            'duration_ms' => $durationMs,
        ]);
    }
}

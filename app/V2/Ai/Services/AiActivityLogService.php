<?php

namespace App\V2\Ai\Services;

use App\Models\AiActivityLog;
use App\Models\User;
use Illuminate\Support\Carbon;

class AiActivityLogService
{
    /**
     * @param  array<string,mixed>|null  $payload
     */
    public function record(
        User $user,
        int $organizationId,
        ?int $conversationId,
        string $tool,
        string $action,
        ?string $entityType = null,
        ?string $entityId = null,
        ?array $payload = null,
        ?string $triggerText = null,
    ): AiActivityLog {
        return AiActivityLog::query()->create([
            'organization_id' => $organizationId,
            'user_id' => $user->id,
            'conversation_id' => $conversationId,
            'tool' => $tool,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'payload' => $payload,
            'trigger_text' => $triggerText,
        ]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function queryForUser(
        User $user,
        int $organizationId,
        ?string $action = null,
        ?string $entityType = null,
        ?string $createdAfter = null,
        ?string $createdBefore = null,
        int $limit = 50,
        ?string $keyword = null,
    ): array {
        $query = AiActivityLog::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->id);

        if ($action) {
            $query->where('action', $action);
        }
        if ($entityType) {
            $query->where('entity_type', $entityType);
        }

        if ($createdAfter) {
            $query->where('created_at', '>=', Carbon::parse($createdAfter));
        }
        if ($createdBefore) {
            $query->where('created_at', '<=', Carbon::parse($createdBefore));
        }
        if ($keyword) {
            $needle = trim($keyword);
            $query->where(function ($q) use ($needle): void {
                $q->where('action', 'like', '%'.$needle.'%')
                    ->orWhere('tool', 'like', '%'.$needle.'%')
                    ->orWhere('entity_type', 'like', '%'.$needle.'%')
                    ->orWhere('entity_id', 'like', '%'.$needle.'%');
            });
        }

        return $query->latest('id')->limit(max(1, min(200, $limit)))->get()->map(function (AiActivityLog $row) {
            return [
                'id' => $row->id,
                'tool' => $row->tool,
                'action' => $row->action,
                'entity_type' => $row->entity_type,
                'entity_id' => $row->entity_id,
                'payload' => $row->payload,
                'trigger_text' => $row->trigger_text,
                'created_at' => $row->created_at?->toIso8601String(),
            ];
        })->all();
    }
}

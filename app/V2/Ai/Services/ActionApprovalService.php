<?php

namespace App\V2\Ai\Services;

use App\Models\AiActionApproval;
use App\Models\AiConversation;
use App\Models\User;
use App\V2\Ai\Enums\AiToolPermission;
use Illuminate\Support\Carbon;

class ActionApprovalService
{
    public function createPending(
        User $user,
        int $organizationId,
        string $tool,
        AiToolPermission $permission,
        array $payload,
        ?AiConversation $conversation = null,
    ): AiActionApproval {
        // One active trigger at a time (web widget + WhatsApp) — newest replaces older.
        $this->supersedePending($user, $organizationId);

        return AiActionApproval::query()->create([
            'organization_id' => $organizationId,
            'user_id' => $user->id,
            'conversation_id' => $conversation?->id,
            'tool' => $tool,
            'permission' => $permission->value,
            'payload' => $payload,
            'status' => 'pending',
        ]);
    }

    public function createPendingWithoutSupersede(
        User $user,
        int $organizationId,
        string $tool,
        AiToolPermission $permission,
        array $payload,
        ?AiConversation $conversation = null,
    ): AiActionApproval {
        return AiActionApproval::query()->create([
            'organization_id' => $organizationId,
            'user_id' => $user->id,
            'conversation_id' => $conversation?->id,
            'tool' => $tool,
            'permission' => $permission->value,
            'payload' => $payload,
            'status' => 'pending',
        ]);
    }

    /**
     * Reject all other pending approvals for this user/org so only the latest CTA remains.
     */
    public function supersedePending(User $user, int $organizationId): int
    {
        return AiActionApproval::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->where('status', 'pending')
            ->update([
                'status' => 'rejected',
                'decided_at' => Carbon::now(),
                'decided_by' => $user->id,
            ]);
    }

    public function approve(AiActionApproval $approval, User $decider): AiActionApproval
    {
        $approval->update([
            'status' => 'approved',
            'decided_at' => Carbon::now(),
            'decided_by' => $decider->id,
        ]);

        return $approval->refresh();
    }

    public function reject(AiActionApproval $approval, User $decider): AiActionApproval
    {
        $approval->update([
            'status' => 'rejected',
            'decided_at' => Carbon::now(),
            'decided_by' => $decider->id,
        ]);

        return $approval->refresh();
    }

    public function findPendingForUser(User $user, int $organizationId, int $id): ?AiActionApproval
    {
        return AiActionApproval::query()
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->where('status', 'pending')
            ->first();
    }
}

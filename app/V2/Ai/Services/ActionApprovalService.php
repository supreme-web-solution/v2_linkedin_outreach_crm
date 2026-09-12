<?php

namespace App\V2\Ai\Services;

use App\Models\AiActionApproval;
use App\Models\AiConversation;
use App\Models\User;
use App\V2\Ai\Enums\AiToolPermission;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class ActionApprovalService
{
    public function createPending(
        User $user,
        int $organizationId,
        string $tool,
        AiToolPermission $permission,
        array $payload,
        ?AiConversation $conversation = null,
        ?int $workflowRunId = null,
        ?string $scopeHash = null,
    ): AiActionApproval {
        $validation = app(WorkflowPlanValidatorService::class)->validate($user, $organizationId, $tool, $payload);
        if (! $validation['ok']) {
            throw new \InvalidArgumentException(implode(' ', $validation['errors']));
        }

        // One active trigger at a time (web widget + WhatsApp) — newest replaces older.
        $this->supersedePending($user, $organizationId);
        [$workflowRunId, $scopeHash] = $this->resolveWorkflowContext(
            $user,
            $organizationId,
            $conversation,
            $tool,
            $payload,
            $workflowRunId,
            $scopeHash,
        );

        return AiActionApproval::query()->create(
            $this->approvalAttributes(
                $user,
                $organizationId,
                $tool,
                $permission,
                $payload,
                $conversation,
                $workflowRunId,
                $scopeHash,
            ),
        );
    }

    public function createPendingWithoutSupersede(
        User $user,
        int $organizationId,
        string $tool,
        AiToolPermission $permission,
        array $payload,
        ?AiConversation $conversation = null,
        ?int $workflowRunId = null,
        ?string $scopeHash = null,
    ): AiActionApproval {
        $validation = app(WorkflowPlanValidatorService::class)->validate($user, $organizationId, $tool, $payload);
        if (! $validation['ok']) {
            throw new \InvalidArgumentException(implode(' ', $validation['errors']));
        }

        [$workflowRunId, $scopeHash] = $this->resolveWorkflowContext(
            $user,
            $organizationId,
            $conversation,
            $tool,
            $payload,
            $workflowRunId,
            $scopeHash,
        );

        return AiActionApproval::query()->create(
            $this->approvalAttributes(
                $user,
                $organizationId,
                $tool,
                $permission,
                $payload,
                $conversation,
                $workflowRunId,
                $scopeHash,
            ),
        );
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

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function deriveScope(string $tool, array $payload): array
    {
        if (is_array($payload['workflow_scope'] ?? null)) {
            return array_filter(
                $payload['workflow_scope'],
                fn ($value) => $value !== null && $value !== [],
            );
        }

        // Simple destructive deletes stage one approval card — no workflow run required.
        if (in_array($tool, ['delete_campaign', 'delete_resource'], true)) {
            return [];
        }

        $scope = [
            'tool' => $tool,
            'target_count' => $payload['target_count'] ?? $payload['count'] ?? null,
            'channels' => $payload['channels'] ?? null,
            'scheduled_at' => $payload['scheduled_at'] ?? null,
            'campaign_ids' => $payload['campaign_ids'] ?? null,
            'action' => $payload['action'] ?? null,
        ];

        return array_filter($scope, fn ($value) => $value !== null && $value !== []);
    }

    /**
     * @return array{0:?int,1:?string}
     */
    private function resolveWorkflowContext(
        User $user,
        int $organizationId,
        ?AiConversation $conversation,
        string $tool,
        array $payload,
        ?int $workflowRunId,
        ?string $scopeHash,
    ): array {
        if ($workflowRunId !== null || ! Schema::hasTable('ai_workflow_runs')) {
            return [$workflowRunId, $scopeHash];
        }

        $scope = is_array($payload['workflow_scope'] ?? null)
            ? $payload['workflow_scope']
            : $this->deriveScope($tool, $payload);

        if ($scope === []) {
            return [null, null];
        }

        $run = app(WorkflowRunService::class)->createPlannedRun(
            user: $user,
            organizationId: $organizationId,
            conversation: $conversation,
            plan: is_array($payload['plan'] ?? null) ? $payload['plan'] : ['goal' => $payload['goal'] ?? null],
            approvalScope: $scope,
        );
        if (is_array($payload['workflow_steps'] ?? null)) {
            app(WorkflowRunService::class)->addSteps($run, $payload['workflow_steps']);
        }

        return [
            $run->id,
            app(WorkflowRunService::class)->scopeHash($scope),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function approvalAttributes(
        User $user,
        int $organizationId,
        string $tool,
        AiToolPermission $permission,
        array $payload,
        ?AiConversation $conversation,
        ?int $workflowRunId,
        ?string $scopeHash,
    ): array {
        $attributes = [
            'organization_id' => $organizationId,
            'user_id' => $user->id,
            'conversation_id' => $conversation?->id,
            'tool' => $tool,
            'permission' => $permission->value,
            'payload' => $payload,
            'status' => 'pending',
        ];

        if (Schema::hasColumn('ai_action_approvals', 'workflow_run_id')) {
            $attributes['workflow_run_id'] = $workflowRunId;
        }
        if (Schema::hasColumn('ai_action_approvals', 'scope_hash')) {
            $attributes['scope_hash'] = $scopeHash;
        }

        return $attributes;
    }
}

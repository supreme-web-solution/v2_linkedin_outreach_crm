<?php

namespace App\V2\Ai\Services;

use App\Models\AiEmployeeSetting;
use App\Models\User;

class TurnPlanBuilderService
{
    /**
     * @return array<string,mixed>
     */
    public function build(User $user, int $organizationId, string $message, AiEmployeeSetting $settings): array
    {
        $resolved = app(IntentGoalResolverService::class)->resolve($message);
        /** @var WorkspaceContextService $workspaceContext */
        $workspaceContext = app(WorkspaceContextService::class);
        $workspace = $workspaceContext->workspaceGoalProfile($settings);

        return [
            'goal' => $resolved['goal'],
            'required_outcome' => $resolved['required_outcome'],
            'side_effect_budget' => $resolved['side_effect_budget'],
            'constraints' => array_merge(
                is_array($resolved['constraints'] ?? null) ? $resolved['constraints'] : [],
                [
                    'organization_id' => $organizationId,
                    'user_id' => $user->id,
                    'preferred_channels' => $workspace['preferred_channels'] ?? [],
                    'send_policy' => $workspace['send_policy'] ?? 'approval_required',
                    'new_vs_existing_preference' => $workspace['new_vs_existing_preference'] ?? 'reuse_first',
                ]
            ),
            'workspace_goal_profile' => $workspace,
            'built_at' => now()->toIso8601String(),
        ];
    }
}

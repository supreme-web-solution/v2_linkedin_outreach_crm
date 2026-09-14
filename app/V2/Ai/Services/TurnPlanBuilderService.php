<?php

namespace App\V2\Ai\Services;

use App\Models\AiConversation;
use App\Models\AiEmployeeSetting;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class TurnPlanBuilderService
{
    public function __construct(
        private readonly SemanticTurnPlanService $semanticPlanner,
        private readonly IntentGoalResolverService $regexFallback,
        private readonly FallbackTurnPlanSafetyService $fallbackSafety,
        private readonly AiActionLogService $actionLogs,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function build(
        User $user,
        int $organizationId,
        string $message,
        AiEmployeeSetting $settings,
        ?AiConversation $conversation = null,
    ): array {
        $interpreted = $this->semanticPlanner->interpret($user, $organizationId, $message, $conversation);

        if ($interpreted !== null) {
            $plan = $interpreted['enforcement'];
            $plan['semantic_source'] = 'llm';
            $plan['planning_degraded'] = false;
        } else {
            $resolved = $this->regexFallback->resolve($message);
            $reason = config('socifusion_ai.semantic_turn_planner', true)
                ? 'llm_unavailable_or_failed'
                : 'semantic_planner_disabled';

            $plan = $this->fallbackSafety->apply([
                'goal' => $resolved['goal'],
                'objective' => is_array($resolved['objective'] ?? null) ? $resolved['objective'] : [],
                'desired_operation' => (string) ($resolved['desired_operation'] ?? ''),
                'required_outcome' => $resolved['required_outcome'],
                'side_effect_budget' => $resolved['side_effect_budget'],
                'constraints' => is_array($resolved['constraints'] ?? null) ? $resolved['constraints'] : [],
                'execution_preferences' => [
                    'approval_required' => in_array((string) ($resolved['required_outcome'] ?? ''), ['send_now', 'delete_now', 'execute_now'], true),
                    'source' => ($resolved['constraints']['new_only'] ?? false) ? 'new_only' : 'reuse_first',
                ],
                'measurable_expectations' => [
                    'target_count' => $resolved['constraints']['target_count'] ?? null,
                ],
                'built_at' => now()->toIso8601String(),
            ], $reason);

            $this->actionLogs->log(
                user: $user,
                organizationId: $organizationId,
                tool: 'semantic_turn_planner',
                permission: \App\V2\Ai\Enums\AiToolPermission::Read,
                status: 'fallback',
                conversation: $conversation,
                input: ['message' => $message, 'reason' => $reason],
                output: [
                    'required_outcome' => $plan['required_outcome'] ?? null,
                    'side_effect_budget' => $plan['side_effect_budget'] ?? null,
                    'fallback_safety_cap' => $plan['fallback_safety_cap'] ?? false,
                ],
            );
        }

        /** @var WorkspaceContextService $workspaceContext */
        $workspaceContext = app(WorkspaceContextService::class);
        $workspace = $workspaceContext->workspaceGoalProfile($settings);

        $existingConstraints = is_array($plan['constraints'] ?? null) ? $plan['constraints'] : [];
        $semanticChannels = $existingConstraints['preferred_channels'] ?? null;
        $workspaceChannels = $workspace['preferred_channels'] ?? [];

        $plan['constraints'] = array_merge(
            $existingConstraints,
            [
                'organization_id' => $organizationId,
                'user_id' => $user->id,
                'preferred_channels' => (is_array($semanticChannels) && $semanticChannels !== [])
                    ? $semanticChannels
                    : $workspaceChannels,
                'send_policy' => $workspace['send_policy'] ?? 'approval_required',
                'new_vs_existing_preference' => $workspace['new_vs_existing_preference'] ?? 'reuse_first',
            ],
        );

        if (! empty($existingConstraints['audience_ref']) && empty($plan['audience_ref'])) {
            $plan['audience_ref'] = $existingConstraints['audience_ref'];
        }
        if (! empty($existingConstraints['list_name']) && empty($plan['list_name'])) {
            $plan['list_name'] = $existingConstraints['list_name'];
        }

        if (! isset($plan['execution_preferences'])) {
            $plan['execution_preferences'] = [
                'approval_required' => in_array((string) ($plan['required_outcome'] ?? ''), ['send_now', 'delete_now', 'execute_now'], true),
                'source' => $workspace['new_vs_existing_preference'] ?? 'reuse_first',
            ];
        }

        $plan['workspace_goal_profile'] = $workspace;
        $plan = $this->bindMeaningForDownstream($plan, $message, $settings);

        $plan['state_evaluation'] = app(TurnPlanStateEvaluationService::class)
            ->evaluate($user, $organizationId, $plan);

        $requested = (int) ($plan['state_evaluation']['requested_quantity'] ?? 0);
        $outcome = (string) ($plan['required_outcome'] ?? '');
        $singleRecipient = \App\V2\Ai\Support\SingleRecipientTurnGuard::matches($plan, $message);
        $plan['workflow_eligible'] = ! $singleRecipient
            && ! ($plan['state_evaluation']['skipped'] ?? true)
            && $requested > 0
            && in_array($outcome, ['find_only', 'send_now', 'setup_only'], true);

        if ($singleRecipient) {
            $plan['single_recipient_turn'] = true;
            $plan['constraints'] = array_merge(
                is_array($plan['constraints'] ?? null) ? $plan['constraints'] : [],
                [
                    'cold_one_shot' => true,
                    'single_channel_only' => true,
                    'block_list_discovery' => true,
                ],
            );
            // Explicit recipient turns never drive list discovery deficit.
            if (is_array($plan['state_evaluation'] ?? null)) {
                $plan['state_evaluation']['requires_external_discovery'] = false;
                $plan['state_evaluation']['remaining_discovery'] = 0;
                $plan['state_evaluation']['remaining_deficit'] = 0;
            }
        }

        return $plan;
    }

    /**
     * Pass rewritten meaning (ICP + segment + count) to discovery/outreach — not the raw utterance.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function bindMeaningForDownstream(array $plan, string $message, AiEmployeeSetting $settings): array
    {
        $workspace = app(WorkspaceContextService::class);
        $icp = $workspace->storedIcp($settings);
        $profile = $workspace->businessProfile($settings);
        $objective = is_array($plan['objective'] ?? null) ? $plan['objective'] : [];
        $semantic = is_array($plan['semantic'] ?? null) ? $plan['semantic'] : [];
        $segment = trim((string) ($objective['segment'] ?? $semantic['target_segment'] ?? ''));
        $quantity = $objective['quantity']
            ?? $plan['measurable_expectations']['target_count']
            ?? $plan['constraints']['target_count']
            ?? null;
        $handoffQuery = trim((string) ($semantic['handoff_query'] ?? $plan['handoff_query'] ?? ''));
        $handoffBrief = trim((string) ($semantic['handoff_brief'] ?? $plan['handoff_brief'] ?? ''));
        $outcome = (string) ($plan['required_outcome'] ?? '');

        if (in_array($outcome, ['find_only', 'send_now', 'setup_only'], true)) {
            if ($handoffQuery !== '') {
                $objective['criteria'] = $handoffQuery;
                if ($segment === '') {
                    $objective['segment'] = Str::limit($handoffQuery, 200, '');
                }
            } else {
                $meaningParts = array_values(array_unique(array_filter([
                    $segment !== '' ? $segment : null,
                    trim((string) Arr::get($icp, 'summary', '')),
                    Arr::get($icp, 'decision_maker') ? 'Decision maker: '.Arr::get($icp, 'decision_maker') : null,
                    Arr::get($icp, 'industry') ? 'Industry: '.Arr::get($icp, 'industry') : null,
                    Arr::get($icp, 'likely_pain') ? 'Pain: '.Arr::get($icp, 'likely_pain') : null,
                    trim((string) ($profile['summary'] ?? '')),
                    trim((string) ($semantic['geography'] ?? $plan['constraints']['geography'] ?? '')),
                ])));

                if ($meaningParts !== []) {
                    $objective['criteria'] = implode('. ', $meaningParts);
                    if ($segment === '') {
                        $objective['segment'] = Str::limit($meaningParts[0], 200, '');
                    }
                }
            }

            $plan['objective'] = $objective;
        }

        $plan['interpreted_brief'] = $handoffBrief !== ''
            ? $handoffBrief
            : $this->interpretedBrief($plan, $quantity, $message);

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function interpretedBrief(array $plan, mixed $quantity, string $message): string
    {
        $outcome = (string) ($plan['required_outcome'] ?? '');
        $criteria = trim((string) ($plan['objective']['criteria'] ?? $plan['objective']['segment'] ?? ''));
        $count = is_numeric($quantity) ? (int) $quantity : 0;

        return match ($outcome) {
            'find_only' => 'Find '.($count > 0 ? $count.' ' : '').'prospects'
                .($criteria !== '' ? ': '.$criteria : '').'.',
            'setup_only' => 'Stage outreach'.($criteria !== '' ? ' for '.$criteria : '').' without sending.',
            'send_now' => 'Find and start outreach'.($count > 0 ? ' to '.$count : '')
                .($criteria !== '' ? ': '.$criteria : '').'.',
            default => $criteria !== '' ? $criteria : trim($message),
        };
    }
}

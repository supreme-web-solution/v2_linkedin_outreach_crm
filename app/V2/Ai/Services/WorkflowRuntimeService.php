<?php

namespace App\V2\Ai\Services;

use App\Jobs\V2\ContinueWorkflowRunJob;
use App\Models\AiConversation;
use App\Models\AiWorkflowRun;
use App\Models\AiWorkflowStep;
use App\Models\User;
use App\V2\Ai\Support\WorkflowStepTypes;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * State-aware workflow runtime: one tick = one durable orchestration cycle.
 *
 * Continuation occurs via ContinueWorkflowRunJob — never recursive tick() calls.
 */
class WorkflowRuntimeService
{
    /** @var list<string> */
    private const TERMINAL_RUN_STATUSES = ['completed', 'failed', 'blocked', 'cancelled'];

    /** @var list<string> */
    private const ACTIVE_STEP_STATUSES = ['pending', 'running'];

    public function __construct(
        private readonly WorkflowRunService $runs,
        private readonly WorkflowPlanBuilderService $planner,
        private readonly WorkflowStepExecutorService $executor,
        private readonly TurnPlanStateEvaluationService $stateEvaluation,
    ) {}

    /**
     * @param  array<string, mixed>  $plan
     */
    public function start(User $user, int $organizationId, array $plan, ?AiConversation $conversation = null): AiWorkflowRun
    {
        $initialState = is_array($plan['state_evaluation'] ?? null)
            ? $plan['state_evaluation']
            : $this->stateEvaluation->evaluate($user, $organizationId, $plan);

        $run = $this->runs->createPlannedRun($user, $organizationId, $conversation, $plan, [
            'requested_quantity' => $initialState['requested_quantity'] ?? null,
            'constraints' => $initialState['constraints_applied'] ?? [],
        ]);

        $run->update([
            'status' => 'running',
            'started_at' => now(),
            'meta' => [
                'baseline_state' => $initialState,
                'latest_state' => $initialState,
                'discovery_attempts' => 0,
                'cumulative_candidate_delta' => 0,
                'step_history' => [],
            ],
        ]);

        $this->runs->addSteps($run, [[
            'step_key' => WorkflowStepTypes::EVALUATE_EXISTING,
            'sequence' => 0,
            'tool_name' => WorkflowStepTypes::EVALUATE_EXISTING,
            'arguments' => [],
        ]]);

        $evaluateStep = $run->steps()->where('step_key', WorkflowStepTypes::EVALUATE_EXISTING)->first();
        if ($evaluateStep) {
            $this->runs->markStepCompleted($evaluateStep, ['state' => $initialState]);
        }

        $this->dispatchContinuation($run->id);

        return $run->fresh(['steps']);
    }

    /**
     * One durable orchestration cycle — safe to call from queue jobs and retries.
     *
     * @return array{run: AiWorkflowRun, step: AiWorkflowStep|null, completed: bool, blocked: bool, continued: bool, result: array<string, mixed>}
     */
    public function tick(int $workflowRunId): array
    {
        /** @var array{run: AiWorkflowRun, step: AiWorkflowStep|null, plan: array<string, mixed>, meta: array<string, mixed>, baseline: array<string, mixed>, terminal: array<string, mixed>|null} $claimed */
        $claimed = DB::transaction(function () use ($workflowRunId) {
            $run = AiWorkflowRun::query()->lockForUpdate()->find($workflowRunId);
            if (! $run) {
                throw new \RuntimeException("Workflow run {$workflowRunId} not found.");
            }

            if (in_array((string) $run->status, self::TERMINAL_RUN_STATUSES, true)) {
                return [
                    'run' => $run,
                    'step' => null,
                    'plan' => is_array($run->plan) ? $run->plan : [],
                    'meta' => is_array($run->meta) ? $run->meta : [],
                    'baseline' => [],
                    'terminal' => ['completed' => $run->status === 'completed', 'blocked' => $run->status === 'blocked', 'result' => $run->result ?? []],
                ];
            }

            if ((string) $run->status === 'waiting') {
                return [
                    'run' => $run,
                    'step' => null,
                    'plan' => is_array($run->plan) ? $run->plan : [],
                    'meta' => is_array($run->meta) ? $run->meta : [],
                    'baseline' => is_array($run->meta['baseline_state'] ?? null) ? $run->meta['baseline_state'] : [],
                    'terminal' => ['completed' => false, 'blocked' => false, 'waiting' => true, 'result' => []],
                ];
            }

            $user = User::query()->find($run->user_id);
            if (! $user) {
                $this->runs->markFailed($run, 'Workflow user not found.');

                return [
                    'run' => $run->fresh(),
                    'step' => null,
                    'plan' => [],
                    'meta' => [],
                    'baseline' => [],
                    'terminal' => ['completed' => false, 'blocked' => false, 'result' => []],
                ];
            }

            $plan = is_array($run->plan) ? $run->plan : [];
            $meta = is_array($run->meta) ? $run->meta : [];
            $baseline = is_array($meta['baseline_state'] ?? null) ? $meta['baseline_state'] : [];
            $organizationId = (int) $run->organization_id;

            $currentState = $this->stateEvaluation->evaluate($user, $organizationId, $plan);
            $meta['latest_state'] = $currentState;

            if ($this->planner->isOutcomeSatisfied($plan, $currentState, $meta)) {
                $finalResult = $this->buildFinalResult($currentState, $meta);
                $this->runs->markCompleted($run, $finalResult);
                $run->update(['meta' => $meta]);

                return [
                    'run' => $run->fresh(),
                    'step' => null,
                    'plan' => $plan,
                    'meta' => $meta,
                    'baseline' => $baseline,
                    'terminal' => ['completed' => true, 'blocked' => false, 'result' => $finalResult],
                ];
            }

            if ($this->planner->isBlocked($currentState, $meta)) {
                $finalResult = $this->buildFinalResult($currentState, $meta);
                $this->runs->markBlocked($run, 'Discovery attempts exhausted with remaining deficit.', $finalResult);
                $run->update(['meta' => $meta]);

                return [
                    'run' => $run->fresh(),
                    'step' => null,
                    'plan' => $plan,
                    'meta' => $meta,
                    'baseline' => $baseline,
                    'terminal' => ['completed' => false, 'blocked' => true, 'result' => $finalResult],
                ];
            }

            $activeStep = AiWorkflowStep::query()
                ->where('workflow_run_id', $run->id)
                ->whereIn('status', self::ACTIVE_STEP_STATUSES)
                ->orderBy('sequence')
                ->lockForUpdate()
                ->first();

            if ($activeStep !== null) {
                if ($activeStep->status === 'pending') {
                    $this->runs->markStepRunning($activeStep);
                    $activeStep = $activeStep->fresh();
                }

                $run->update(['meta' => $meta, 'plan' => array_merge($plan, ['state_evaluation' => $currentState])]);

                return [
                    'run' => $run->fresh(),
                    'step' => $activeStep,
                    'plan' => $plan,
                    'meta' => $meta,
                    'baseline' => $baseline,
                    'terminal' => null,
                ];
            }

            $nextStepDef = $this->planner->nextStep($plan, $currentState, $meta);
            if ($nextStepDef !== null) {
                if ((int) ($meta['approval_id'] ?? 0) > 0) {
                    $nextStepDef['arguments']['approval_id'] = (int) $meta['approval_id'];
                }
                if (! empty($meta['discovery_list_hash'])) {
                    $nextStepDef['arguments']['list_hash'] = (string) $meta['discovery_list_hash'];
                    $nextStepDef['arguments']['list_src'] = (string) ($meta['discovery_list_src'] ?? 'sn');
                    $nextStepDef['arguments']['list_name'] = (string) ($meta['discovery_list_name'] ?? '');
                }
                if (is_array($meta['discovery_lists'] ?? null) && ($meta['discovery_lists'] ?? []) !== []) {
                    $nextStepDef['arguments']['discovery_lists'] = $meta['discovery_lists'];
                }
            }
            if ($nextStepDef === null) {
                $finalResult = $this->buildFinalResult($currentState, $meta);
                $this->runs->markCompleted($run, $finalResult);
                $run->update(['meta' => $meta]);

                return [
                    'run' => $run->fresh(),
                    'step' => null,
                    'plan' => $plan,
                    'meta' => $meta,
                    'baseline' => $baseline,
                    'terminal' => ['completed' => true, 'blocked' => false, 'result' => $finalResult],
                ];
            }

            $step = AiWorkflowStep::query()->create([
                'workflow_run_id' => $run->id,
                'step_key' => (string) $nextStepDef['step_key'],
                'sequence' => (int) ($nextStepDef['sequence'] ?? 1),
                'tool_name' => (string) ($nextStepDef['tool_name'] ?? ''),
                'arguments' => is_array($nextStepDef['arguments'] ?? null) ? $nextStepDef['arguments'] : [],
                'status' => 'pending',
                'depends_on' => [],
                'approval_required' => (bool) ($nextStepDef['approval_required'] ?? false),
                'approval_status' => ($nextStepDef['approval_required'] ?? false) ? 'pending' : 'not_required',
            ]);

            $this->runs->markStepRunning($step);
            $run->update(['meta' => $meta, 'plan' => array_merge($plan, ['state_evaluation' => $currentState])]);

            return [
                'run' => $run->fresh(),
                'step' => $step->fresh(),
                'plan' => $plan,
                'meta' => $meta,
                'baseline' => $baseline,
                'terminal' => null,
                'next_step_def' => $nextStepDef,
            ];
        });

        if ($claimed['terminal'] !== null) {
            if (($claimed['terminal']['completed'] ?? false) && $claimed['run']->status === 'completed') {
                app(WorkflowConversationNotifier::class)->notifyCompleted($claimed['run']);
            }

            return [
                'run' => $claimed['run'],
                'step' => null,
                'completed' => (bool) ($claimed['terminal']['completed'] ?? false),
                'blocked' => (bool) ($claimed['terminal']['blocked'] ?? false),
                'waiting' => (bool) ($claimed['terminal']['waiting'] ?? false),
                'continued' => false,
                'result' => is_array($claimed['terminal']['result']) ? $claimed['terminal']['result'] : [],
            ];
        }

        $step = $claimed['step'];
        if (! $step instanceof AiWorkflowStep) {
            return [
                'run' => $claimed['run'],
                'step' => null,
                'completed' => false,
                'blocked' => false,
                'continued' => false,
                'result' => [],
            ];
        }

        $step->refresh();
        if ($step->status === 'completed') {
            $this->dispatchContinuationIfNeeded($claimed['run']->id);

            return [
                'run' => $claimed['run']->fresh(['steps']),
                'step' => $step,
                'completed' => false,
                'blocked' => false,
                'continued' => true,
                'result' => ['already_completed' => true, 'step_result' => $step->result],
            ];
        }

        if ($step->status === 'waiting') {
            return [
                'run' => $claimed['run'],
                'step' => $step,
                'completed' => false,
                'blocked' => false,
                'waiting' => true,
                'continued' => false,
                'result' => ['waiting' => true, 'step_result' => $step->result],
            ];
        }

        if ($step->status !== 'running') {
            return [
                'run' => $claimed['run'],
                'step' => $step,
                'completed' => false,
                'blocked' => false,
                'continued' => false,
                'result' => ['skipped' => 'step_not_runnable'],
            ];
        }

        $run = $claimed['run'];
        $user = User::query()->find($run->user_id);
        if (! $user) {
            return [
                'run' => $run,
                'step' => $step,
                'completed' => false,
                'blocked' => false,
                'continued' => false,
                'result' => [],
            ];
        }

        $nextStepDef = $claimed['next_step_def'] ?? $this->stepDefFromRecord($step, $run);
        $nextStepDef['workflow_run_id'] = $run->id;
        $nextStepDef['conversation_id'] = $run->conversation_id;
        $organizationId = (int) $run->organization_id;
        $plan = array_merge($claimed['plan'], ['state_evaluation' => $claimed['meta']['latest_state'] ?? []]);
        $meta = $claimed['meta'];
        $baseline = $claimed['baseline'];

        try {
            $stepResult = $this->executor->execute($user, $organizationId, $nextStepDef, $plan, $baseline);
            $stepType = (string) ($nextStepDef['step_type'] ?? '');

            if ($stepType === WorkflowStepTypes::DISCOVER) {
                $meta['discovery_attempts'] = (int) ($meta['discovery_attempts'] ?? 0) + 1;
                $delta = is_array($stepResult['state_delta'] ?? null) ? $stepResult['state_delta'] : [];
                $providerSaved = (int) ($stepResult['provider_returned'] ?? $stepResult['saved_reported'] ?? 0);
                $fromState = max(0, (int) ($delta['candidate_delta'] ?? 0));
                $meta['cumulative_candidate_delta'] = max(
                    (int) ($meta['cumulative_candidate_delta'] ?? 0),
                    $fromState,
                    $providerSaved,
                );
            }

            if ($stepType === WorkflowStepTypes::DISCOVER) {
                if (! empty($stepResult['list_hash'])) {
                    $meta['discovery_list_hash'] = (string) $stepResult['list_hash'];
                    $meta['discovery_list_src'] = (string) ($stepResult['list_src'] ?? 'sn');
                    $meta['discovery_list_name'] = (string) ($stepResult['list_name'] ?? '');
                }
                if (is_array($stepResult['discovery_lists'] ?? null)) {
                    $meta['discovery_lists'] = $stepResult['discovery_lists'];
                }
                if (is_array($stepResult['platforms_searched'] ?? null)) {
                    $meta['platforms_searched'] = $stepResult['platforms_searched'];
                }
                if (empty($meta['discovery_list_hash']) && is_array($stepResult['discovery_lists'] ?? null)) {
                    foreach ($stepResult['discovery_lists'] as $listRow) {
                        if (! is_array($listRow) || empty($listRow['list_hash'])) {
                            continue;
                        }
                        $meta['discovery_list_hash'] = (string) $listRow['list_hash'];
                        $meta['discovery_list_src'] = (string) ($listRow['list_src'] ?? 'sn');
                        $meta['discovery_list_name'] = (string) ($listRow['list_name'] ?? '');
                        if (($listRow['primary_channel'] ?? '') === 'linkedin') {
                            break;
                        }
                    }
                }
            }

            if ($stepType === WorkflowStepTypes::PREPARE_OUTREACH) {
                $meta['prepared'] = true;
                $meta['approval_id'] = (int) ($stepResult['approval_id'] ?? 0);
                if (is_array($stepResult['approval_ids'] ?? null)) {
                    $meta['approval_ids'] = $stepResult['approval_ids'];
                }
            }

            $meta['latest_state'] = $stepResult['state_after'] ?? $meta['latest_state'] ?? [];

            if (($stepResult['waiting'] ?? false) === true) {
                $meta['awaiting_approval'] = true;
                DB::transaction(function () use ($run, $step, $stepResult, $meta, $plan) {
                    $lockedStep = AiWorkflowStep::query()->lockForUpdate()->find($step->id);
                    if ($lockedStep && $lockedStep->status !== 'waiting') {
                        $this->runs->markStepWaiting($lockedStep, $stepResult);
                    }
                    $lockedRun = AiWorkflowRun::query()->lockForUpdate()->find($run->id);
                    if ($lockedRun) {
                        $this->runs->markWaiting($lockedRun, WorkflowStepTypes::AWAITING_APPROVAL);
                        $lockedRun->update([
                            'meta' => $meta,
                            'plan' => array_merge($plan, ['state_evaluation' => $meta['latest_state']]),
                        ]);
                    }
                });

                $freshRun = $run->fresh(['steps']);
                app(WorkflowConversationNotifier::class)->notifyWaitingForApproval($freshRun);

                return [
                    'run' => $freshRun,
                    'step' => $step->fresh(),
                    'completed' => false,
                    'blocked' => false,
                    'waiting' => true,
                    'continued' => false,
                    'result' => ['step_result' => $stepResult, 'latest_state' => $meta['latest_state']],
                ];
            }

            $meta['step_history'][] = [
                'step_id' => $step->id,
                'step_key' => $step->step_key,
                'status' => 'completed',
                'result_summary' => [
                    'provider_returned' => $stepResult['provider_returned'] ?? null,
                    'approval_id' => $stepResult['approval_id'] ?? null,
                    'state_delta' => $stepResult['state_delta'] ?? null,
                    'target_count' => ($step->arguments ?? [])['target_count'] ?? null,
                ],
            ];

            DB::transaction(function () use ($run, $step, $stepResult, $meta, $plan) {
                $lockedStep = AiWorkflowStep::query()->lockForUpdate()->find($step->id);
                if (! $lockedStep || in_array($lockedStep->status, ['completed', 'waiting'], true)) {
                    return;
                }

                $this->runs->markStepCompleted($lockedStep, $stepResult);

                $lockedRun = AiWorkflowRun::query()->lockForUpdate()->find($run->id);
                if ($lockedRun) {
                    $lockedRun->update([
                        'meta' => $meta,
                        'plan' => array_merge($plan, ['state_evaluation' => $meta['latest_state']]),
                        'status' => $lockedRun->status === 'waiting' ? 'running' : $lockedRun->status,
                    ]);
                }
            });

            $freshRun = AiWorkflowRun::query()->find($run->id);
            $freshMeta = is_array($freshRun?->meta) ? $freshRun->meta : $meta;
            $reEvaluated = is_array($freshMeta['latest_state']) ? $freshMeta['latest_state'] : [];
            $completed = $this->planner->isWorkflowComplete($plan, $reEvaluated, $freshMeta);

            if (
                $stepType === WorkflowStepTypes::DISCOVER
                && ! $completed
                && $this->planner->isProspectQuotaMet($plan, $reEvaluated, $freshMeta)
                && $freshRun
            ) {
                app(WorkflowConversationNotifier::class)->notifyDiscoveryComplete($freshRun);
            }

            if ($completed && $freshRun) {
                $this->runs->markCompleted($freshRun, $this->buildFinalResult($reEvaluated, $freshMeta));
                app(WorkflowConversationNotifier::class)->notifyCompleted($freshRun->fresh());
            } elseif ($this->planner->isBlocked($reEvaluated, $freshMeta) && $freshRun) {
                $this->runs->markBlocked($freshRun, 'Discovery attempts exhausted with remaining deficit.', $this->buildFinalResult($reEvaluated, $freshMeta));
            } else {
                $this->dispatchContinuation($run->id);
            }

            return [
                'run' => $freshRun?->fresh(['steps']) ?? $run,
                'step' => $step->fresh(),
                'completed' => $completed,
                'blocked' => false,
                'waiting' => false,
                'continued' => ! $completed,
                'result' => [
                    'step_result' => $stepResult,
                    'latest_state' => $reEvaluated,
                ],
            ];
        } catch (Throwable $e) {
            $this->runs->markStepFailed($step, $e->getMessage());
            $this->runs->incrementStepRetry($step);
            $step = $step->fresh();

            $freshMeta = is_array($run->meta) ? $run->meta : $meta;
            if ((int) $step->retry_count >= WorkflowPlanBuilderService::MAX_DISCOVERY_ATTEMPTS) {
                $currentState = $this->stateEvaluation->evaluate($user, $organizationId, $plan);
                $this->runs->markBlocked($run, $e->getMessage(), $this->buildFinalResult($currentState, $freshMeta));
            } else {
                $step->update(['status' => 'pending', 'started_at' => null]);
                $this->dispatchContinuation($run->id);
            }

            return [
                'run' => $run->fresh(['steps']),
                'step' => $step,
                'completed' => false,
                'blocked' => (int) $step->retry_count >= WorkflowPlanBuilderService::MAX_DISCOVERY_ATTEMPTS,
                'continued' => (int) $step->retry_count < WorkflowPlanBuilderService::MAX_DISCOVERY_ATTEMPTS,
                'result' => ['error' => $e->getMessage()],
            ];
        }
    }

    public function markRunFailedFromJob(int $workflowRunId, string $error): void
    {
        $run = AiWorkflowRun::query()->find($workflowRunId);
        if ($run && ! in_array((string) $run->status, self::TERMINAL_RUN_STATUSES, true)) {
            $this->runs->markFailed($run, $error);
        }
    }

    /**
     * Resume a workflow after the user LAUNCHes a staged campaign plan.
     *
     * @param  array<string, mixed>  $launchResult
     */
    public function resumeAfterLaunch(int $workflowRunId, int $approvalId, array $launchResult): void
    {
        DB::transaction(function () use ($workflowRunId, $approvalId, $launchResult) {
            $run = AiWorkflowRun::query()->lockForUpdate()->find($workflowRunId);
            if (! $run || in_array((string) $run->status, self::TERMINAL_RUN_STATUSES, true)) {
                return;
            }

            $meta = is_array($run->meta) ? $run->meta : [];
            $plan = is_array($run->plan) ? $run->plan : [];

            $step = AiWorkflowStep::query()
                ->where('workflow_run_id', $run->id)
                ->where('step_key', WorkflowStepTypes::AWAITING_APPROVAL)
                ->lockForUpdate()
                ->first();

            if ($step && $step->status === 'waiting') {
                $this->runs->markStepCompleted($step, array_merge($launchResult, [
                    'approval_id' => $approvalId,
                    'launched' => true,
                ]));
            }

            $meta['executed'] = true;
            $meta['awaiting_approval'] = false;
            $meta['approval_id'] = $approvalId;
            $meta['outreach_campaign_id'] = (int) ($launchResult['outreach_campaign_id'] ?? 0);
            $history = is_array($meta['step_history'] ?? null) ? $meta['step_history'] : [];
            $history[] = [
                'step_key' => 'launch',
                'status' => 'completed',
                'result_summary' => [
                    'approval_id' => $approvalId,
                    'outreach_campaign_id' => $meta['outreach_campaign_id'],
                ],
            ];
            $meta['step_history'] = $history;

            $state = is_array($meta['latest_state'] ?? null)
                ? $meta['latest_state']
                : (is_array($meta['baseline_state'] ?? null) ? $meta['baseline_state'] : []);
            $meta['latest_state'] = $state;
            $run->update(['meta' => $meta, 'status' => 'running']);

            if ($this->planner->isWorkflowComplete($plan, $state, $meta)) {
                $this->runs->markCompleted($run, $this->buildFinalResult($state, $meta));
                app(WorkflowConversationNotifier::class)->notifyCompleted($run->fresh());
            }
        });
    }

    public function dispatchContinuation(int $workflowRunId): void
    {
        ContinueWorkflowRunJob::dispatch($workflowRunId);
    }

    private function dispatchContinuationIfNeeded(int $workflowRunId): void
    {
        $run = AiWorkflowRun::query()->find($workflowRunId);
        if ($run && ! in_array((string) $run->status, self::TERMINAL_RUN_STATUSES, true)) {
            $this->dispatchContinuation($workflowRunId);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function stepDefFromRecord(AiWorkflowStep $step, AiWorkflowRun $run): array
    {
        $tool = (string) $step->tool_name;
        $stepType = match ($tool) {
            'discover_prospects' => WorkflowStepTypes::DISCOVER,
            'draft_campaign_plan' => WorkflowStepTypes::PREPARE_OUTREACH,
            'awaiting_user_approval' => WorkflowStepTypes::AWAITING_APPROVAL,
            default => (string) $step->step_key,
        };

        $arguments = is_array($step->arguments) ? $step->arguments : [];
        $meta = is_array($run->meta) ? $run->meta : [];
        if ($stepType === WorkflowStepTypes::AWAITING_APPROVAL && (int) ($meta['approval_id'] ?? 0) > 0) {
            $arguments['approval_id'] = (int) $meta['approval_id'];
        }

        return [
            'step_key' => $step->step_key,
            'sequence' => $step->sequence,
            'tool_name' => $tool,
            'step_type' => $stepType,
            'arguments' => $arguments,
            'approval_required' => (bool) $step->approval_required,
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function buildFinalResult(array $state, array $meta): array
    {
        $discoveredThisRun = (int) ($meta['cumulative_candidate_delta'] ?? 0);
        $planner = app(WorkflowPlanBuilderService::class);
        $eligible = $planner->requiresNetNewDiscovery($state) && $discoveredThisRun > 0
            ? $discoveredThisRun
            : (int) ($state['intersection_eligible_count'] ?? $state['eligible_count'] ?? 0);

        return [
            'requested' => $state['requested_quantity'] ?? null,
            'eligible' => $eligible,
            'discovered_this_run' => $discoveredThisRun,
            'remaining_deficit' => $planner->effectiveRemainingDiscovery($state, $meta),
            'discovery_attempts' => $meta['discovery_attempts'] ?? 0,
            'approval_id' => $meta['approval_id'] ?? null,
            'outreach_campaign_id' => $meta['outreach_campaign_id'] ?? null,
            'prepared' => $meta['prepared'] ?? false,
            'executed' => $meta['executed'] ?? false,
            'step_history' => $meta['step_history'] ?? [],
            'baseline_state' => $meta['baseline_state'] ?? null,
            'final_state' => $state,
        ];
    }

}

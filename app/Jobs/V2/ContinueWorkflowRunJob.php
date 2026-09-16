<?php

namespace App\Jobs\V2;

use App\Models\AiConversation;
use App\Models\AiWorkflowRun;
use App\V2\Ai\Services\WebChatProcessingService;
use App\V2\Ai\Services\WorkflowRuntimeService;
use App\V2\Support\TransientDatabaseException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One durable workflow orchestration cycle: plan → execute one step → re-evaluate → schedule next continuation.
 *
 * Concurrency is handled by WorkflowRuntimeService DB locks — not ShouldBeUnique (which blocks
 * re-dispatch while this job is still running).
 *
 * MySQL connection loss is treated as infrastructure: release & retry. Never mark the step/run
 * failed for "Connection refused" — that permanently poisons durable workflows.
 */
class ContinueWorkflowRunJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries;

    public int $timeout;

    /** @var list<int> */
    public array $backoff = [15, 60, 180, 300, 600];

    public function __construct(
        public readonly int $workflowRunId,
    ) {
        $this->tries = max(3, (int) config('socifusion_ai.workflow_job_tries', 5));
        $this->timeout = max(120, (int) config('socifusion_ai.workflow_job_timeout', 600));
        $this->onQueue((string) config('socifusion_ai.workflow_queue_name', 'default'));
    }

    public function handle(WorkflowRuntimeService $runtime): void
    {
        try {
            $run = AiWorkflowRun::query()->find($this->workflowRunId);
        } catch (Throwable $e) {
            $this->releaseForInfrastructureFailure($e);

            return;
        }

        if (! $run) {
            return;
        }

        if (in_array((string) $run->status, ['completed', 'failed', 'blocked', 'cancelled'], true)) {
            return;
        }

        if ((string) $run->status === 'waiting') {
            return;
        }

        $conversation = null;
        try {
            $conversation = $run->conversation_id
                ? AiConversation::query()->find($run->conversation_id)
                : null;

            if ($conversation) {
                app(WebChatProcessingService::class)->update(
                    $conversation,
                    'workflow',
                    'Workflow #'.$this->workflowRunId.' — working in the background…',
                );
            }

            $result = $runtime->tick($this->workflowRunId);

            if ($conversation) {
                $processing = app(WebChatProcessingService::class);
                if (($result['waiting'] ?? false) || ($result['completed'] ?? false) || ($result['blocked'] ?? false)) {
                    $processing->clearAll($conversation->fresh() ?? $conversation);
                } elseif ($result['continued'] ?? false) {
                    $processing->update(
                        $conversation->fresh() ?? $conversation,
                        'workflow',
                        'Workflow #'.$this->workflowRunId.' — continuing to next step…',
                    );
                }
            }
        } catch (Throwable $e) {
            if (TransientDatabaseException::matches($e)) {
                $this->releaseForInfrastructureFailure($e);

                return;
            }

            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $hadInfraFailure = Cache::pull($this->infraFailureCacheKey()) === true;
        $isInfra = TransientDatabaseException::matches($exception)
            || ($exception instanceof MaxAttemptsExceededException && $hadInfraFailure);

        if ($isInfra) {
            Log::warning('[Soci] Workflow continuation exhausted during infrastructure failure — scheduling soft resume', [
                'workflow_run_id' => $this->workflowRunId,
                'error' => $exception?->getMessage(),
            ]);

            $this->scheduleSoftResume();

            return;
        }

        try {
            $run = AiWorkflowRun::query()->find($this->workflowRunId);
            if ($run?->conversation_id) {
                $conversation = AiConversation::query()->find($run->conversation_id);
                if ($conversation) {
                    app(WebChatProcessingService::class)->clearAll($conversation);
                }
            }

            app(WorkflowRuntimeService::class)->markRunFailedFromJob(
                $this->workflowRunId,
                $exception?->getMessage() ?? 'Workflow continuation job failed.',
            );
        } catch (Throwable $e) {
            Log::error('[Soci] ContinueWorkflowRunJob::failed could not persist terminal state', [
                'workflow_run_id' => $this->workflowRunId,
                'original' => $exception?->getMessage(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function releaseForInfrastructureFailure(Throwable $e): void
    {
        Log::warning('[Soci] Workflow tick deferred — transient database failure', [
            'workflow_run_id' => $this->workflowRunId,
            'attempt' => method_exists($this, 'attempts') ? $this->attempts() : null,
            'error' => $e->getMessage(),
        ]);

        Cache::put($this->infraFailureCacheKey(), true, now()->addHours(6));

        try {
            DB::purge();
            DB::reconnect();
        } catch (Throwable) {
            // Next attempt / next worker process will reconnect.
        }

        $delay = $this->nextReleaseDelay();
        if (method_exists($this, 'release')) {
            $this->release($delay);
        } else {
            throw $e;
        }
    }

    private function scheduleSoftResume(): void
    {
        try {
            $run = AiWorkflowRun::query()->find($this->workflowRunId);
            if (! $run || in_array((string) $run->status, ['completed', 'failed', 'blocked', 'cancelled', 'waiting'], true)) {
                return;
            }

            $meta = is_array($run->meta) ? $run->meta : [];
            $infraCycles = (int) ($meta['infra_resume_cycles'] ?? 0);
            if ($infraCycles >= 12) {
                app(WorkflowRuntimeService::class)->markRunFailedFromJob(
                    $this->workflowRunId,
                    'Workflow paused after repeated database outages. Ask Soci to continue when the database is healthy.',
                );

                return;
            }

            $meta['infra_resume_cycles'] = $infraCycles + 1;
            $meta['infra_last_resume_at'] = now()->toIso8601String();
            $run->update(['meta' => $meta]);

            $delayMinutes = min(30, 5 * ($infraCycles + 1));
            self::dispatch($this->workflowRunId)->delay(now()->addMinutes($delayMinutes));
        } catch (Throwable $e) {
            Log::error('[Soci] Could not schedule workflow soft resume', [
                'workflow_run_id' => $this->workflowRunId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function infraFailureCacheKey(): string
    {
        return 'workflow:infra-failure:'.$this->workflowRunId;
    }

    private function nextReleaseDelay(): int
    {
        $attempt = max(1, method_exists($this, 'attempts') ? (int) $this->attempts() : 1);
        $index = min($attempt - 1, count($this->backoff) - 1);

        return (int) $this->backoff[$index];
    }
}

<?php

namespace App\Jobs\V2;

use App\Models\AiConversation;
use App\Models\AiWorkflowRun;
use App\V2\Ai\Services\WebChatProcessingService;
use App\V2\Ai\Services\WorkflowRuntimeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * One durable workflow orchestration cycle: plan → execute one step → re-evaluate → schedule next continuation.
 *
 * Concurrency is handled by WorkflowRuntimeService DB locks — not ShouldBeUnique (which blocks
 * re-dispatch while this job is still running).
 */
class ContinueWorkflowRunJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [15, 60, 180];

    public function __construct(
        public readonly int $workflowRunId,
    ) {
        $this->onQueue((string) config('socifusion_ai.workflow_queue_name', 'default'));
    }

    public function handle(WorkflowRuntimeService $runtime): void
    {
        $run = AiWorkflowRun::query()->find($this->workflowRunId);
        $conversation = $run?->conversation_id
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
    }

    public function failed(?Throwable $exception): void
    {
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
    }
}

<?php

namespace App\Jobs\V2;

use App\Models\User;
use App\V2\Ai\Services\AgentOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Heavy LLM + tool turn — dispatched by ProcessWebAiChatJob so the queue worker
 * is not blocked on one long-running monolithic job.
 */
class RunWebAgentTurnJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries;

    public int $timeout;

    /** @var list<int> */
    public array $backoff = [30, 90, 180];

    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $userId,
        public readonly int $organizationId,
        public readonly int $conversationId,
        public readonly int $userMessageId,
        public readonly string $promptMessage,
    ) {
        $this->tries = 1;
        $this->timeout = max(120, (int) config('socifusion_ai.web_chat_job_timeout', 600));
        $this->onQueue((string) config('socifusion_ai.web_chat_agent_queue_name', 'default'));
    }

    public function uniqueId(): string
    {
        return 'web-agent-turn:'.$this->conversationId.':'.$this->userMessageId;
    }

    public function handle(AgentOrchestrator $orchestrator): void
    {
        $user = User::query()->find($this->userId);
        if (! $user) {
            return;
        }

        $orchestrator->continueQueuedWebTurn(
            user: $user,
            organizationId: $this->organizationId,
            conversationId: $this->conversationId,
            userMessageId: $this->userMessageId,
            promptMessage: $this->promptMessage,
        );
    }

    public function failed(?Throwable $exception): void
    {
        $user = User::query()->find($this->userId);
        if (! $user) {
            return;
        }

        app(AgentOrchestrator::class)->failQueuedWebTurn(
            user: $user,
            organizationId: $this->organizationId,
            conversationId: $this->conversationId,
            userMessageId: $this->userMessageId,
            exception: $exception,
        );
    }
}

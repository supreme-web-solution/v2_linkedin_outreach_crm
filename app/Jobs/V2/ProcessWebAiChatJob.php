<?php

namespace App\Jobs\V2;

use App\Models\User;
use App\V2\Ai\Services\AgentOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessWebAiChatJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public readonly int $userId,
        public readonly int $organizationId,
        public readonly int $conversationId,
        public readonly int $userMessageId,
        public readonly string $promptMessage,
    ) {
        $this->onQueue((string) config('socifusion_ai.web_chat_queue_name', 'webhooks'));
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
}

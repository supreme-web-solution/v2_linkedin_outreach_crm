<?php

namespace App\Jobs\V2;

use App\Models\AiConversation;
use App\Models\User;
use App\V2\Ai\Services\WebChatProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fast coordinator: mark processing + dispatch RunWebAgentTurnJob.
 */
class ProcessWebAiChatJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 30;

    public function __construct(
        public readonly int $userId,
        public readonly int $organizationId,
        public readonly int $conversationId,
        public readonly int $userMessageId,
        public readonly string $promptMessage,
    ) {
        $this->onQueue((string) config('socifusion_ai.web_chat_queue_name', 'webhooks'));
    }

    public function handle(WebChatProcessingService $processing): void
    {
        $user = User::query()->find($this->userId);
        $conversation = AiConversation::query()->find($this->conversationId);

        if (! $user || ! $conversation) {
            return;
        }

        $processing->start($conversation, 'Thinking…');

        RunWebAgentTurnJob::dispatch(
            $this->userId,
            $this->organizationId,
            $this->conversationId,
            $this->userMessageId,
            $this->promptMessage,
        );
    }
}

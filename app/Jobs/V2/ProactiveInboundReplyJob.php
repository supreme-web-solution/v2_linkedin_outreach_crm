<?php

namespace App\Jobs\V2;

use App\V2\Ai\Services\ProactiveInboundReplyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProactiveInboundReplyJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(
        public readonly int $v2ConversationId,
        public readonly int $userId,
        public readonly int $inboundMessageId,
    ) {
        $this->onQueue((string) config('socifusion_ai.proactive_inbound_queue_name', 'webhooks'));
    }

    public function handle(ProactiveInboundReplyService $service): void
    {
        $service->handle($this->v2ConversationId, $this->userId, $this->inboundMessageId);
    }
}

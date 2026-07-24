<?php

namespace App\Jobs\V2;

use App\Models\User;
use App\Models\V2Call;
use App\V2\Services\CallOrchestrationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class HandleCallInboundReplyJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $callId,
        public readonly string $message
    ) {
        $this->onQueue('default');
    }

    public function handle(CallOrchestrationService $orchestration): void
    {
        $call = V2Call::query()->find($this->callId);
        if (!$call) {
            return;
        }

        $user = User::query()->find($call->user_id);
        if (!$user) {
            return;
        }

        $orchestration->handleInboundReply($call, $this->message, $user, 'prospect');
    }
}

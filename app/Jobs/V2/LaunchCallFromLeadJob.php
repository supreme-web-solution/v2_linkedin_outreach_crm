<?php

namespace App\Jobs\V2;

use App\Models\User;
use App\Models\V2Call;
use App\Models\V2IntegrationAccount;
use App\V2\Services\CallOrchestrationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class LaunchCallFromLeadJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [15, 60, 180];

    public function __construct(public readonly int $callId)
    {
        $this->onQueue('outreach');
    }

    public function handle(CallOrchestrationService $orchestration): void
    {
        $call = V2Call::query()->find($this->callId);
        if (!$call || !$call->connection_id) {
            return;
        }

        $user = User::query()->find($call->user_id);
        if (!$user) {
            return;
        }

        if (!V2IntegrationAccount::activeUnipileAccountId($user->id)) {
            return;
        }

        $orchestration->launchCallChat($call, $user, (int) $call->organization_id);
    }

    public function failed(Throwable $exception): void
    {
        app(CallOrchestrationService::class)->rollbackFailedLaunch($this->callId, $exception);
    }
}

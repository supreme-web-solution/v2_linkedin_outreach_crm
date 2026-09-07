<?php

namespace App\Jobs\V2;

use App\Models\AiChannelIdentity;
use App\Models\User;
use App\V2\Ai\Services\AgentOrchestrator;
use App\V2\Ai\Services\ZernioTypingIndicatorService;
use App\V2\Ai\Services\ZernioWebhookService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessZernioInboundMessageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly int $identityId,
        public readonly int $userId,
        public readonly int $organizationId,
        public readonly string $message,
        public readonly ?string $providerMessageId = null,
    ) {
        $this->onQueue('webhooks');
    }

    public function handle(AgentOrchestrator $orchestrator, ZernioWebhookService $webhook, ZernioTypingIndicatorService $typing): void
    {
        $identity = AiChannelIdentity::query()->find($this->identityId);
        $user = User::query()->find($this->userId);

        if (! $identity || ! $user || $identity->status !== 'active') {
            return;
        }

        $typing->begin($identity);

        try {
            $result = $orchestrator->handle(
                user: $user,
                organizationId: $this->organizationId,
                message: $this->message,
                channel: 'whatsapp',
                channelIdentityId: $identity->id,
                providerMessageId: $this->providerMessageId,
            );

            $webhook->deliverOrchestratorReply($identity->fresh() ?? $identity, $user, $result);
        } finally {
            $typing->end($identity->id);
        }
    }
}

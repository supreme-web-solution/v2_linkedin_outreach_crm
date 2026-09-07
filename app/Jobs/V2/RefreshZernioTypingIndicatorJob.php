<?php

namespace App\Jobs\V2;

use App\V2\Ai\Services\ZernioTypingIndicatorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RefreshZernioTypingIndicatorJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $identityId,
    ) {
        $this->onQueue('webhooks');
    }

    public function handle(ZernioTypingIndicatorService $typing): void
    {
        $typing->refreshIfActive($this->identityId);
    }
}

<?php

namespace Tests\Unit\V2\Ai;

use App\Jobs\V2\RunWebAgentTurnJob;
use App\V2\Ai\Services\WebChatTurnRecoveryService;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class WebChatTurnRecoveryServiceTest extends TestCase
{
    public function test_has_queued_turn_job_detects_unique_lock(): void
    {
        $conversationId = 42;
        $userMessageId = 99;
        $probe = new RunWebAgentTurnJob(1, 1, $conversationId, $userMessageId, 'hello');
        $lock = Cache::lock(UniqueLock::getKey($probe), 60);
        $this->assertTrue($lock->get());

        try {
            $service = app(WebChatTurnRecoveryService::class);
            $this->assertTrue($service->hasQueuedTurnJob($conversationId, $userMessageId));
            $this->assertFalse($service->hasQueuedTurnJob($conversationId, $userMessageId + 1));
        } finally {
            $lock->release();
        }
    }
}

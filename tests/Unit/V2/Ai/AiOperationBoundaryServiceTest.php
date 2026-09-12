<?php

namespace Tests\Unit\V2\Ai;

use App\V2\Ai\Services\AiOperationBoundaryService;
use Tests\TestCase;

class AiOperationBoundaryServiceTest extends TestCase
{
    public function test_allows_copy_only_context(): void
    {
        app(AiOperationBoundaryService::class)->assertCopyOnlyContext(
            'Write a short LinkedIn first message asking if they currently do outbound.'
        );

        $this->assertTrue(true);
    }

    public function test_blocks_action_execution_context(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(AiOperationBoundaryService::class)->assertCopyOnlyContext(
            'Find 100 leads and launch outreach now'
        );
    }
}

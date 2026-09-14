<?php

namespace Tests\Unit\V2\Integrations\Mindcase;

use App\V2\Integrations\Mindcase\MindcaseClient;
use Tests\TestCase;

class MindcaseClientTimeoutTest extends TestCase
{
    public function test_timeouts_scale_for_max_100_pull(): void
    {
        config()->set('services.mindcase.timeout', 300);
        config()->set('services.mindcase.poll_seconds', 2);
        config()->set('services.mindcase.max_poll_attempts', 90);
        config()->set('services.mindcase.poll_timeout_seconds', 600);
        config()->set('socifusion_ai.max_prospect_pull', 100);

        $client = app(MindcaseClient::class);

        // 50-profile production run timed out around ~3.5 min under the old 60-attempt cap.
        $this->assertGreaterThanOrEqual(370, $client->httpTimeoutSeconds(50));
        $this->assertGreaterThanOrEqual(150, $client->pollAttempts(50));
        $this->assertGreaterThanOrEqual(300, $client->pollAttempts(50) * 2);

        // Max pull (100) must have more headroom than 50.
        $this->assertGreaterThanOrEqual(600, $client->httpTimeoutSeconds(100));
        $this->assertSame(300, $client->pollAttempts(100));
        $this->assertSame(600, $client->pollAttempts(100) * 2);
        $this->assertGreaterThan(
            $client->pollAttempts(50) * 2,
            $client->pollAttempts(100) * 2
        );
    }
}

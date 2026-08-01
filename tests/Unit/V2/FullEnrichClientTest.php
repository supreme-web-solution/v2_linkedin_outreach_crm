<?php

namespace Tests\Unit\V2;

use App\V2\Enrichment\LeadEnrichmentInput;
use App\V2\Services\FullEnrichClient;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FullEnrichClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        FullEnrichClient::resetCreditsExhausted();

        Config::set('services.fullenrich.api_key', 'test-key');
        Config::set('services.fullenrich.poll_timeout_seconds', 30);
        Config::set('services.fullenrich.poll_interval_seconds', 1);
    }

    public function test_stops_polling_immediately_when_credits_insufficient(): void
    {
        Http::fake([
            'https://app.fullenrich.com/api/v2/contact/enrich/bulk' => Http::response(['enrichment_id' => 'job-1'], 200),
            'https://app.fullenrich.com/api/v2/contact/enrich/bulk/job-1' => Http::response(['status' => 'CREDITS_INSUFFICIENT', 'data' => []], 402),
        ]);

        $client = new FullEnrichClient;
        $input = new LeadEnrichmentInput(
            firstName: 'Jane',
            lastName: 'Doe',
            linkedinUrl: 'https://www.linkedin.com/in/jane-doe',
        );

        $result = $client->enrich($input);

        $this->assertFalse($result->hasAnyContact());
        $this->assertTrue($client->creditsExhausted());

        Http::assertSentCount(2);
    }

    public function test_skips_api_calls_after_credits_exhausted(): void
    {
        FullEnrichClient::markCreditsExhausted();

        Http::fake();

        $client = new FullEnrichClient;
        $input = new LeadEnrichmentInput(
            firstName: 'Jane',
            lastName: 'Doe',
            linkedinUrl: 'https://www.linkedin.com/in/jane-doe',
        );

        $client->enrich($input);

        Http::assertNothingSent();
    }
}

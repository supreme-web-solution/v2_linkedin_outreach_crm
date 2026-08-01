<?php

namespace Tests\Unit\V2;

use App\V2\Enrichment\LeadEnrichmentInput;
use App\V2\Enrichment\LeadEnrichmentResult;
use App\V2\Services\FullEnrichClient;
use App\V2\Services\LeadEnrichmentService;
use App\V2\Services\LinkedInProfileSocialExtractor;
use App\V2\Services\UnipileProfileEmailService;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadEnrichmentServiceTest extends TestCase
{
    use RefreshDatabase;
    public function test_fullenrich_runs_when_unipile_returns_nothing(): void
    {
        config([
            'services.fullenrich.api_key' => 'test-key',
            'services.fullenrich.poll_interval_seconds' => 0,
        ]);

        Http::fake([
            'app.fullenrich.com/api/v2/contact/enrich/bulk' => Http::response(['enrichment_id' => 'abc-123']),
            'app.fullenrich.com/api/v2/contact/enrich/bulk/abc-123' => Http::response([
                'status' => 'FINISHED',
                'data' => [[
                    'contact_info' => [
                        'most_probable_work_email' => ['email' => 'jane@acme.com'],
                        'most_probable_phone' => ['number' => '+15551234567'],
                    ],
                    'profile' => [
                        'social_profiles' => [
                            'twitter' => ['handle' => 'janedoe'],
                        ],
                    ],
                ]],
            ]),
        ]);

        $service = new LeadEnrichmentService(
            $this->createMock(UnipileProfileEmailService::class),
            new LinkedInProfileSocialExtractor,
            new FullEnrichClient,
        );

        $user = new \App\Models\User;
        $user->id = 1;

        $input = new LeadEnrichmentInput(
            firstName: 'Jane',
            lastName: 'Doe',
            linkedinUrl: 'https://www.linkedin.com/in/janedoe',
            companyDomain: 'acme.com',
        );

        $result = $service->enrich($user, $input);

        $this->assertSame('jane@acme.com', $result->email);
        $this->assertSame('+15551234567', $result->phone);
        $this->assertSame('janedoe', $result->twitterHandle);
        $this->assertContains('fullenrich', $result->sources);
        $this->assertTrue($result->emailLookupAttempted);
        $this->assertTrue($result->phoneLookupAttempted);
    }

    public function test_marks_phone_not_found_when_enrich_finishes_without_phone(): void
    {
        config(['services.fullenrich.api_key' => null]);

        Http::fake();

        $service = new LeadEnrichmentService(
            $this->createMock(UnipileProfileEmailService::class),
            new LinkedInProfileSocialExtractor,
            new FullEnrichClient,
        );

        $user = new \App\Models\User;
        $user->id = 1;

        $result = $service->enrich($user, new LeadEnrichmentInput(
            firstName: 'Jane',
            lastName: 'Doe',
            linkedinUrl: 'https://www.linkedin.com/in/janedoe',
        ));

        $this->assertTrue($result->emailLookupAttempted);
        $this->assertTrue($result->phoneLookupAttempted);
        $this->assertNull($result->phone);
    }

    public function test_fullenrich_skipped_when_not_configured(): void
    {
        config(['services.fullenrich.api_key' => null]);

        Http::fake();

        $service = new LeadEnrichmentService(
            $this->createMock(UnipileProfileEmailService::class),
            new LinkedInProfileSocialExtractor,
            new FullEnrichClient,
        );

        $user = new \App\Models\User;
        $user->id = 1;

        $result = $service->enrich($user, new LeadEnrichmentInput(
            firstName: 'Jane',
            lastName: 'Doe',
            linkedinUrl: 'https://www.linkedin.com/in/janedoe',
        ));

        $this->assertInstanceOf(LeadEnrichmentResult::class, $result);
        $this->assertFalse($result->hasAnyContact());
        Http::assertNothingSent();
    }
}

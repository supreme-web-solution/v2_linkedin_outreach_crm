<?php

namespace Tests\Unit\V2\Outreach;

use App\V2\Outreach\OutreachContactEnrichmentService;
use Tests\TestCase;

class OutreachContactEnrichmentServiceTest extends TestCase
{
    public function test_handle_from_profile_url_extracts_instagram_username(): void
    {
        $service = app(OutreachContactEnrichmentService::class);

        $this->assertSame(
            'prospects',
            $service->handleFromProfileUrl('https://www.instagram.com/prospects/', 'instagram'),
        );
        $this->assertSame(
            'founder',
            $service->handleFromProfileUrl('https://instagram.com/@founder?hl=en', 'instagram'),
        );
        $this->assertSame('', $service->handleFromProfileUrl('https://www.linkedin.com/in/jane', 'instagram'));
    }
}

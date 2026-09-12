<?php

namespace Tests\Unit\V2\Ai;

use App\V2\Ai\Support\ResearchUrlValidator;
use Tests\TestCase;

class ResearchUrlValidatorTest extends TestCase
{
    public function test_rejects_scheme_only_urls(): void
    {
        $this->assertFalse(ResearchUrlValidator::isUsable('https://'));
        $this->assertFalse(ResearchUrlValidator::isUsable('http://'));
        $this->assertNull(ResearchUrlValidator::sanitize('https://'));
    }

    public function test_accepts_real_urls(): void
    {
        $this->assertTrue(ResearchUrlValidator::isUsable('https://example.com'));
        $this->assertSame('https://example.com', ResearchUrlValidator::sanitize('https://example.com'));
    }

    public function test_filter_list_drops_invalid(): void
    {
        $this->assertSame(
            ['https://example.com'],
            ResearchUrlValidator::filterList(['https://', 'https://example.com']),
        );
    }
}

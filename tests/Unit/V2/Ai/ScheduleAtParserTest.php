<?php

namespace Tests\Unit\V2\Ai;

use App\V2\Ai\Support\ScheduleAtParser;
use Carbon\Carbon;
use Tests\TestCase;

class ScheduleAtParserTest extends TestCase
{
    public function test_parses_thursday_morning(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-07 15:00:00', 'UTC'));

        $parsed = ScheduleAtParser::parse('Thursday morning', 'UTC');

        $this->assertNotNull($parsed);
        $this->assertSame('Thursday', $parsed->format('l'));
        $this->assertSame(9, (int) $parsed->format('G'));
        $this->assertTrue($parsed->isFuture());

        Carbon::setTestNow();
    }

    public function test_parses_iso_datetime(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-07 15:00:00', 'UTC'));

        $parsed = ScheduleAtParser::parse('2026-09-11 09:30:00', 'UTC');

        $this->assertNotNull($parsed);
        $this->assertSame('2026-09-11 09:30:00', $parsed->format('Y-m-d H:i:s'));

        Carbon::setTestNow();
    }

    public function test_returns_null_for_empty_input(): void
    {
        $this->assertNull(ScheduleAtParser::parse(null));
        $this->assertNull(ScheduleAtParser::parse(''));
    }
}

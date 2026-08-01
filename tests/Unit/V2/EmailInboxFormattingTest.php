<?php

namespace Tests\Unit\V2;

use App\V2\Services\EmailAddressQuality;
use App\V2\Services\EmailBodyFormatter;
use Tests\TestCase;

class EmailInboxFormattingTest extends TestCase
{
    public function test_email_body_formatter_extracts_reply_and_strips_quote_markers(): void
    {
        $raw = "I am not interested at this time.\n\nOn Mon, Jul 27, 2026 at 12:21 AM sender@company.com wrote:\n> Hi william,\n> I hope this message finds you well!";

        $formatted = app(EmailBodyFormatter::class)->format($raw);

        $this->assertSame('I am not interested at this time.', $formatted['main']);
        $this->assertStringContainsString('Hi william,', (string) $formatted['quoted']);
        $this->assertStringNotContainsString('>', (string) $formatted['quoted']);
    }

    public function test_email_body_formatter_splits_inline_on_wrote_header(): void
    {
        $raw = 'That would be fun! On Mon, Jul 27, 2026 at 12:38 AM vicken408@gmail.com wrote:';

        $formatted = app(EmailBodyFormatter::class)->format($raw);

        $this->assertSame('That would be fun!', $formatted['main']);
        $this->assertNull($formatted['quoted']);
    }

    public function test_email_body_formatter_strips_on_wrote_header_before_quoted_lines(): void
    {
        $raw = "Yes, I would be interested.\n\nOn Mon, Jul 27, 2026 at 12:05 AM vicken408@gmail.com wrote:\n> Hi william,\n> Thanks for reaching out.";

        $formatted = app(EmailBodyFormatter::class)->format($raw);

        $this->assertSame('Yes, I would be interested.', $formatted['main']);
        $this->assertStringNotContainsString('wrote:', $formatted['main']);
        $this->assertStringContainsString('Hi william,', (string) $formatted['quoted']);
    }

    public function test_email_body_formatter_strips_multiline_on_wrote_header(): void
    {
        $raw = "That would be fun!\n\nOn Mon, Jul 27, 2026 at 12:38 AM\nvicken408@gmail.com\nwrote:\n> Previous message body";

        $formatted = app(EmailBodyFormatter::class)->format($raw);

        $this->assertSame('That would be fun!', $formatted['main']);
        $this->assertStringNotContainsString('wrote:', $formatted['main']);
        $this->assertSame('Mon, Jul 27, 2026 at 12:38 AM · vicken408@gmail.com', $formatted['quote_header']);
        $this->assertStringContainsString('Previous message body', (string) $formatted['quoted']);
    }

    public function test_email_body_formatter_exposes_compact_quote_header_for_inline_reply(): void
    {
        $raw = 'That would be fun! On Mon, Jul 27, 2026 at 12:38 AM vicken408@gmail.com wrote:';

        $formatted = app(EmailBodyFormatter::class)->format($raw);

        $this->assertSame('That would be fun!', $formatted['main']);
        $this->assertSame('Mon, Jul 27, 2026 at 12:38 AM · vicken408@gmail.com', $formatted['quote_header']);
        $this->assertNull($formatted['quoted']);
    }

    public function test_email_address_quality_flags_placeholder_domains(): void
    {
        $quality = app(EmailAddressQuality::class);

        $this->assertSame('placeholder', $quality->assess('john@example.com')['level']);
        $this->assertSame('placeholder', $quality->assess('prospect@email.com')['level']);
        $this->assertSame('ok', $quality->assess('vickenconcept@gmail.com')['level']);
    }
}

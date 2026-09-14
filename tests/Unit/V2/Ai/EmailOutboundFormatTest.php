<?php

namespace Tests\Unit\V2\Ai;

use App\V2\Ai\Support\EmailOutboundFormat;
use Tests\TestCase;

class EmailOutboundFormatTest extends TestCase
{
    public function test_formats_dense_body_into_paragraphs(): void
    {
        $dense = 'Hi Vicken, I spent time reviewing BuildTrust’s model for diaspora families building in Africa. '
            .'The trust mechanism is unusually concrete: companies are vetted, projects use milestones, and funds stay in escrow. '
            .'With 500+ active projects listed, that workflow likely creates a meaningful operating layer. '
            .'VickenConcepts builds custom software for operational workflows where scale depends on reliable handoffs. '
            .'Which part of the workflow is most manual for your team today: verification, evidence review, or family updates?';

        $plain = EmailOutboundFormat::formatPlainBody($dense);
        $this->assertStringContainsString("Hi Vicken,\n\n", $plain);
        $this->assertGreaterThanOrEqual(3, substr_count($plain, "\n\n"));
    }

    public function test_html_body_uses_paragraph_tags(): void
    {
        $html = EmailOutboundFormat::toHtmlBody("Hi Ada,\n\nNoticed your ops workflow.\n\nOpen to a quick chat?");
        $this->assertStringContainsString('<p', $html);
        $this->assertStringContainsString('Hi Ada,', $html);
        $this->assertGreaterThanOrEqual(2, substr_count($html, '<p'));
    }

    public function test_rejects_weak_subjects_and_builds_from_research(): void
    {
        $this->assertTrue(EmailOutboundFormat::isWeakSubject('Quick note'));
        $this->assertTrue(EmailOutboundFormat::isWeakSubject('Hello'));

        $subject = EmailOutboundFormat::normalizeSubject(
            'Quick note',
            "Title: BuildTrust\nURL: https://example.com\nEscrow milestones for diaspora construction."
        );

        $this->assertStringContainsString('BuildTrust', $subject);
        $this->assertFalse(EmailOutboundFormat::isWeakSubject($subject));
    }
}

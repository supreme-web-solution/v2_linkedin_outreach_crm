<?php

namespace Tests\Unit\V2\Ai;

use App\V2\Ai\Support\RecipientFacingCopyGuard;
use PHPUnit\Framework\TestCase;

class RecipientFacingCopyGuardTest extends TestCase
{
    public function test_detects_internal_action_plan(): void
    {
        $bad = 'Reply with the event details and calendar link: The Viconcept Show will be held online on Saturday, September 19, 2026 at 12:00 PM. Thank them for their interest, explain that the facilitator role includes guiding a session and engaging attendees, and invite them to book a short planning call through the calendar link. Ask them to confirm their time zone so we can state the program time accurately.';

        $this->assertTrue(RecipientFacingCopyGuard::looksLikeInternalPlan($bad));
        $this->assertNotEmpty(RecipientFacingCopyGuard::problems($bad));
    }

    public function test_allows_normal_recipient_email(): void
    {
        $good = "Hi Vicken,\n\nThanks for your interest in facilitating at the Viconcept Show on September 19, 2026 at 12:00 PM.\n\nHere's a link to book a short planning call:\nhttps://example.com/book\n\nBest,\nWilliam Victor";

        $this->assertFalse(RecipientFacingCopyGuard::looksLikeInternalPlan($good));
        $this->assertFalse(RecipientFacingCopyGuard::hasUnresolvedPlaceholders($good));
        $this->assertSame([], RecipientFacingCopyGuard::problems($good));
    }

    public function test_detects_placeholders(): void
    {
        $this->assertTrue(RecipientFacingCopyGuard::hasUnresolvedPlaceholders('Kind regards, [Your Name]'));
        $this->assertTrue(RecipientFacingCopyGuard::hasUnresolvedPlaceholders('Hi {{firstName}},'));
    }
}

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

    public function test_prepare_outbound_fills_sender_from_user_name(): void
    {
        $user = new \App\Models\User(['name' => 'William Victor', 'email' => 'vicken408@gmail.com']);
        $filled = RecipientFacingCopyGuard::prepareOutbound(
            "Hi there,\n\nPlease join us.\n\nBest,\n[Your Name]",
            ['user' => $user],
        );

        $this->assertStringContainsString('William Victor', $filled);
        $this->assertStringNotContainsString('[Your Name]', $filled);
        $this->assertSame([], RecipientFacingCopyGuard::problems($filled));
    }

    public function test_prepare_outbound_prefers_explicit_sender_over_profile(): void
    {
        $user = new \App\Models\User(['name' => 'Profile Name', 'email' => 'vicken408@gmail.com']);
        $filled = RecipientFacingCopyGuard::prepareOutbound(
            "Best,\n[Your Name]",
            ['user' => $user, 'sender_name' => 'Custom Signer'],
        );

        $this->assertStringContainsString('Custom Signer', $filled);
        $this->assertStringNotContainsString('Profile Name', $filled);
        $this->assertStringNotContainsString('[Your Name]', $filled);
    }

    public function test_extract_sender_from_user_instruction(): void
    {
        $this->assertSame(
            'William Victor',
            \App\V2\Ai\Support\SenderIdentity::extractFromText(
                'Invite them and sign as William Victor please'
            ),
        );
    }

    public function test_prepare_outbound_fills_first_name_tag(): void
    {
        $filled = RecipientFacingCopyGuard::prepareOutbound(
            'Hi {{firstName}}, free to facilitate?',
            ['first_name' => 'Vicken'],
        );

        $this->assertSame('Hi Vicken, free to facilitate?', $filled);
        $this->assertFalse(RecipientFacingCopyGuard::hasUnresolvedPlaceholders($filled));
    }
}

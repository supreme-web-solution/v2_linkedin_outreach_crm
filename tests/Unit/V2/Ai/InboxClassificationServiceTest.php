<?php

namespace Tests\Unit\V2\Ai;

use App\V2\Ai\Services\InboxClassificationService;
use PHPUnit\Framework\TestCase;

class InboxClassificationServiceTest extends TestCase
{
    public function test_classifies_hot_meeting_intent(): void
    {
        $service = new InboxClassificationService;

        $result = $service->classifyWithKeywords('Yes, interested — can we schedule a demo next week?');

        $this->assertSame('hot', $result['priority']);
        $this->assertSame('meeting_request', $result['intent']);
        $this->assertContains('meeting', $result['evidence']);
    }

    public function test_classifies_opt_out(): void
    {
        $service = new InboxClassificationService;

        $result = $service->classifyWithKeywords('Please unsubscribe me');

        $this->assertSame('low_priority', $result['priority']);
        $this->assertSame('opt_out', $result['intent']);
        $this->assertSame('closed_lost', $result['stage']);
    }

    public function test_classifies_tell_me_more_as_wants_info(): void
    {
        $service = new InboxClassificationService;

        $result = $service->classifyWithKeywords('Can you tell me more about how onboarding works?');

        $this->assertSame('needs_judgment', $result['priority']);
        $this->assertSame('wants_info', $result['intent']);
        $this->assertSame('hard', $result['buying_signal']);
        $this->assertSame('read', $result['asset_preference']);
    }

    public function test_classifies_outbound_answer_as_qualifying_not_a_pitch(): void
    {
        $service = new InboxClassificationService;

        $result = $service->classifyWithKeywords('Yes, we do outbound — mostly LinkedIn.');

        $this->assertSame('qualifying_answer', $result['intent']);
        $this->assertSame('soft', $result['buying_signal']);
    }

    public function test_classifies_ill_take_a_look_as_will_review(): void
    {
        $service = new InboxClassificationService;

        $result = $service->classifyWithKeywords("Thanks, I'll take a look.");

        $this->assertSame('will_review', $result['intent']);
        $this->assertSame('soft', $result['buying_signal']);
    }

    public function test_classifies_webinar_ask_as_wants_watch(): void
    {
        $service = new InboxClassificationService;

        $result = $service->classifyWithKeywords('Can I watch a walkthrough of how it works?');

        $this->assertSame('wants_watch', $result['intent']);
        $this->assertSame('watch', $result['asset_preference']);
    }
}

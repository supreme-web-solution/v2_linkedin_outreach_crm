<?php

namespace Tests\Unit\V2\Ai;

use App\V2\Ai\Services\InboxClassificationService;
use PHPUnit\Framework\TestCase;

class InboxClassificationServiceTest extends TestCase
{
    public function test_classifies_hot_meeting_intent(): void
    {
        $service = new InboxClassificationService;

        $result = $service->classify('Yes, interested — can we schedule a demo next week?');

        $this->assertSame('hot', $result['priority']);
        $this->assertSame('meeting_request', $result['intent']);
        $this->assertContains('meeting', $result['evidence']);
    }

    public function test_classifies_opt_out(): void
    {
        $service = new InboxClassificationService;

        $result = $service->classify('Please unsubscribe me');

        $this->assertSame('low_priority', $result['priority']);
        $this->assertSame('opt_out', $result['intent']);
        $this->assertSame('closed_lost', $result['stage']);
    }

    public function test_classifies_question_as_needs_judgment(): void
    {
        $service = new InboxClassificationService;

        $result = $service->classify('Can you tell me more about how onboarding works?');

        $this->assertSame('needs_judgment', $result['priority']);
        $this->assertSame('question', $result['intent']);
    }
}

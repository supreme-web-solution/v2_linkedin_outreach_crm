<?php

namespace Tests\Unit\V2\Ai;

use App\V2\Ai\Services\UserTurnIntentService;
use Tests\TestCase;

class UserTurnIntentInboxReplyTest extends TestCase
{
    public function test_detects_inbox_reply_requests(): void
    {
        $intent = app(UserTurnIntentService::class);

        $this->assertTrue($intent->isInboxReplyRequest('generate the email response for vickenconcept'));
        $this->assertTrue($intent->isInboxReplyRequest('draft a reply for vickenconcept@gmail.com'));
        $this->assertFalse($intent->isInboxReplyRequest('find 30 linkedin prospects'));
    }

    public function test_extracts_email_target(): void
    {
        $intent = app(UserTurnIntentService::class);
        $target = $intent->extractInboxReplyTarget('draft reply for vickenconcept@gmail.com');

        $this->assertSame('vickenconcept@gmail.com', $target['email']);
    }

    public function test_respects_draft_only_guard(): void
    {
        $intent = app(UserTurnIntentService::class);

        $this->assertTrue($intent->wantsDraftOnly('generate a reply but don\'t send yet'));
        $this->assertFalse($intent->wantsDraftOnly('generate the email response for vickenconcept'));
    }
}

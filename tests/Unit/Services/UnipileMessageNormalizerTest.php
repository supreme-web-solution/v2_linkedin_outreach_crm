<?php

namespace Tests\Unit\Services;

use App\V2\Services\UnipileMessageNormalizer;
use Tests\TestCase;

class UnipileMessageNormalizerTest extends TestCase
{
    public function test_skips_hidden_and_event_messages(): void
    {
        $normalizer = new UnipileMessageNormalizer();

        $this->assertTrue($normalizer->shouldSkipAsChatMessage(['hidden' => true, 'text' => '👍']));
        $this->assertTrue($normalizer->shouldSkipAsChatMessage(['is_event' => true, 'event_type' => 1]));
        $this->assertFalse($normalizer->shouldSkipAsChatMessage(['text' => 'Hello']));
    }

    public function test_extracts_attachments_without_downloading(): void
    {
        $normalizer = new UnipileMessageNormalizer();
        $attachments = $normalizer->extractAttachments([
            'attachments' => [[
                'id' => 'att_1',
                'type' => 'img',
                'mimetype' => 'image/jpeg',
                'filename' => 'photo.jpg',
            ]],
        ]);

        $this->assertSame('att_1', $attachments[0]['id']);
        $this->assertSame('photo.jpg', $attachments[0]['filename']);
    }

    public function test_merges_reactions_on_message(): void
    {
        $normalizer = new UnipileMessageNormalizer();
        $merged = $normalizer->mergeReactions(
            [['value' => '👍', 'sender_id' => 'u1', 'is_sender' => false]],
            [['value' => '❤️', 'sender_id' => 'u2', 'is_sender' => true]],
        );

        $this->assertCount(2, $merged);
    }

    public function test_parses_whatsapp_reaction_announcement_text(): void
    {
        $normalizer = new UnipileMessageNormalizer();

        $parsed = $normalizer->parseReactionAnnouncementText('{{2349036802727@s.whatsapp.net}} reacted 🙏');

        $this->assertSame('2349036802727@s.whatsapp.net', $parsed['sender_id']);
        $this->assertSame('🙏', $parsed['value']);
        $this->assertTrue($normalizer->isReactionAnnouncementText('{{2349036802727@s.whatsapp.net}} reacted 🙏'));
        $this->assertTrue($normalizer->shouldSkipAsChatMessage([
            'text' => '{{2349036802727@s.whatsapp.net}} reacted 🙏',
        ]));
    }

    public function test_treats_numeric_hidden_flags_as_truthy(): void
    {
        $normalizer = new UnipileMessageNormalizer();

        $this->assertTrue($normalizer->shouldSkipAsChatMessage(['hidden' => 1, 'text' => '👍']));
        $this->assertTrue($normalizer->shouldSkipAsChatMessage(['is_event' => '1', 'event_type' => 2, 'text' => '👍']));
    }
}

<?php

namespace Tests\Unit\V2\Ai;

use App\V2\Ai\Services\PlanChannelIntentService;
use Tests\TestCase;

class PlanChannelIntentServiceTest extends TestCase
{
    public function test_mixed_first_experiment_wants_parallel_discovery(): void
    {
        $intent = app(PlanChannelIntentService::class);

        $payload = [
            'first_experiment' => true,
            'preferred_channels' => 'Instagram + LinkedIn + Email',
            'channels' => 'Instagram + LinkedIn + Email',
            'discovery_channels' => ['instagram', 'linkedin'],
            'include_email' => true,
        ];

        $this->assertTrue($intent->wantsParallelDiscovery($payload));
        $this->assertTrue($intent->wantsEmail($payload));
        $this->assertSame(['instagram', 'linkedin'], $intent->discoveryChannels($payload));
    }

    public function test_locked_single_list_does_not_fan_out_again(): void
    {
        $intent = app(PlanChannelIntentService::class);

        $this->assertFalse($intent->wantsParallelDiscovery([
            'preferred_channels' => 'Instagram + LinkedIn',
            'single_channel_only' => true,
            'list_hash' => 'abc123',
            'primary_channel' => 'linkedin',
        ]));
    }

    public function test_instagram_keyword_prefers_buyer_niche_over_titles_and_seller_pitch(): void
    {
        $intent = app(PlanChannelIntentService::class);

        $keyword = $intent->instagramKeyword(
            [
                'audience' => 'Chief Technology Officer OR IT Director AND custom software development AND AI solutions',
                'goal' => 'VickenConcepts sells custom software development and AI-powered solutions',
            ],
            [
                'who_we_sell_to' => 'agency owners selling B2B services',
                'search_query' => 'custom software development AI solutions',
                'decision_maker' => 'Chief Technology Officer',
            ],
        );

        $this->assertStringContainsString('agency', strtolower($keyword));
        $this->assertStringNotContainsString('Chief Technology Officer OR', $keyword);
        $this->assertStringNotContainsString('custom software development', strtolower($keyword));
    }

    public function test_send_channels_cover_every_outreach_platform(): void
    {
        $intent = app(PlanChannelIntentService::class);

        foreach (['linkedin', 'instagram', 'email', 'whatsapp', 'telegram', 'twitter'] as $channel) {
            $this->assertTrue($intent->isSendChannel($channel), $channel.' should be a send channel');
            $this->assertNotEmpty($intent->conversationSequence($channel));
        }

        $this->assertFalse($intent->isSendChannel('google_calendar'));
        $this->assertSame('csv', $intent->defaultListSrc('instagram'));
        $this->assertSame('csv', $intent->defaultListSrc('twitter'));
        $this->assertSame('sn', $intent->defaultListSrc('linkedin'));
        $this->assertSame('sn', $intent->defaultListSrc('whatsapp'));
    }
}

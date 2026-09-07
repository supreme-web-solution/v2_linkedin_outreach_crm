<?php

namespace Tests\Unit\V2\Ai;

use App\V2\Ai\Services\CampaignOptimizerService;
use App\V2\Outreach\OutreachCampaignStatsService;
use App\V2\Outreach\OutreachSequenceResolver;
use PHPUnit\Framework\TestCase;

class CampaignOptimizerServiceTest extends TestCase
{
    public function test_suggests_low_reply_rate_fix(): void
    {
        $service = new CampaignOptimizerService(
            new OutreachCampaignStatsService,
            new OutreachSequenceResolver,
        );

        $suggestions = $service->suggestionsFromStats(
            [
                'total_leads' => 50,
                'reply_rate' => 1.5,
                'invite_accepted_rate' => 5,
                'by_status' => ['error' => 0],
                'steps_failed' => 0,
                'actions_by_channel' => ['linkedin' => 40],
            ],
            [],
            [],
        );

        $titles = array_column($suggestions, 'title');
        $this->assertContains('Low reply rate', $titles);
    }

    public function test_suggests_attach_lists_when_empty(): void
    {
        $service = new CampaignOptimizerService(
            new OutreachCampaignStatsService,
            new OutreachSequenceResolver,
        );

        $suggestions = $service->suggestionsFromStats(
            ['total_leads' => 0],
            [],
            [],
        );

        $this->assertSame('Attach lead lists', $suggestions[0]['title']);
    }
}

<?php

namespace Tests\Unit\V2\Ai;

use App\V2\Ai\Support\CampaignDisplayName;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

class CampaignDisplayNameTest extends TestCase
{
    public function test_uses_explicit_campaign_name(): void
    {
        $name = CampaignDisplayName::fromPlan(
            'Invite a@b.com to facilitate at the Supreme Web Solution Annual Event and ask whether they are available.',
            ['campaign_name' => 'Annual event invite', 'channels' => 'Email'],
        );

        $this->assertSame('AI: Annual event invite', $name);
    }

    public function test_shortens_long_goal_with_emails(): void
    {
        $name = CampaignDisplayName::fromPlan(
            'Invite vickenconcept@gmail.com and websupreme000@gmail.com to facilitate at the Supreme Web Solution Annual Event and ask whether they are available.',
            ['channels' => 'Email'],
        );

        $this->assertStringStartsWith('AI: ', $name);
        $this->assertLessThanOrEqual(64, mb_strlen($name));
        $this->assertStringNotContainsString('@gmail.com', $name);
        $this->assertStringNotContainsString('ask whether', Str::lower($name));
    }

    public function test_keyword_title_for_annual_event(): void
    {
        $name = CampaignDisplayName::fromPlan(
            'Invite people to our annual event as facilitators',
            ['channels' => 'Email'],
        );

        $this->assertSame('AI: Annual event invite · Email', $name);
    }
}

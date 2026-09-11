<?php

namespace Tests\Unit\V2\Ai;

use App\V2\Ai\Services\TurnExecutionLedger;
use Tests\TestCase;

class TurnExecutionLedgerTest extends TestCase
{
    protected function tearDown(): void
    {
        app(TurnExecutionLedger::class)->reset();
        parent::tearDown();
    }

    public function test_report_covers_every_platform_not_just_linkedin(): void
    {
        $ledger = app(TurnExecutionLedger::class);
        $ledger->recordOutreach('conversation-first outreach', [
            'summary' => 'You asked for 10. Split across connected searchable platforms: 6 LinkedIn, 4 Instagram.',
            'allocation' => ['linkedin' => 6, 'instagram' => 4],
        ], [
            'linkedin' => [
                'best_match' => ['list_name' => 'B2B owners', 'total_leads' => 6],
                'sample_profiles' => [['name' => 'Ada', 'headline' => 'Founder']],
            ],
            'instagram' => [
                'best_match' => ['list_name' => 'IG: B2B', 'total_leads' => 4],
                'sample_profiles' => [['name' => 'nila', 'headline' => 'Agency']],
            ],
        ], [
            ['channel' => 'linkedin', 'name' => 'AI: Conversation-first · LinkedIn (6)', 'url' => 'http://127.0.0.1:8000/outreach/4', 'status_label' => 'launched'],
            ['channel' => 'instagram', 'name' => 'AI: Conversation-first · Instagram (4)', 'url' => 'http://127.0.0.1:8000/outreach/5', 'status_label' => 'launched'],
        ]);

        $report = $ledger->report();

        $this->assertStringContainsString('6 LinkedIn', $report);
        $this->assertStringContainsString('4 Instagram', $report);
        $this->assertStringContainsString('Ada', $report);
        $this->assertStringContainsString('nila', $report);
        $this->assertStringContainsString('/outreach/5', $report);
        $this->assertStringContainsString('Goal: start a conversation', $report);
    }

    public function test_discovery_report_surfaces_failed_instagram_channel(): void
    {
        $ledger = app(TurnExecutionLedger::class);
        $ledger->recordDiscovery('ideal customers', [
            'summary' => 'Split across connected searchable platforms: 50 LinkedIn, 50 Instagram.',
            'allocation' => ['linkedin' => 50, 'instagram' => 50],
        ], [
            'linkedin' => [
                'best_match' => ['list_name' => 'B2B owners', 'total_leads' => 50],
                'sample_profiles' => [['name' => 'Ada', 'headline' => 'Founder']],
            ],
            'instagram' => [
                'best_match' => null,
                'total_leads_in_matches' => 0,
                'failure_reason' => 'Mindcase job abc timed out waiting for results.',
                'sample_profiles' => [],
            ],
        ]);

        $report = $ledger->report();

        $this->assertStringContainsString('Saved 50 prospect', $report);
        $this->assertStringContainsString('Split: 50 Linkedin', $report);
        $this->assertStringContainsString('Instagram: could not save', $report);
        $this->assertStringContainsString('timed out waiting for results', $report);
        $this->assertStringNotContainsString('Split: 50 Linkedin + 50 Instagram', $report);
    }
}

<?php

namespace Tests\Unit\V2\Ai;

use App\Models\AiActionApproval;
use App\V2\Ai\Services\AiEmployeeSettingsService;
use App\V2\Ai\Services\CommandCenterService;
use App\V2\Ai\Support\ApprovalActionLabels;
use Tests\TestCase;

class DeleteCampaignApprovalGuardTest extends TestCase
{
    public function test_delete_tools_are_never_auto_executable(): void
    {
        $settings = app(AiEmployeeSettingsService::class);

        $this->assertTrue($settings->isDestructiveTool('delete_campaign'));
        $this->assertTrue($settings->isDestructiveTool('delete_something_else'));
        $this->assertFalse($settings->isDestructiveTool('pause_outreach_campaign'));

        $this->assertSame(
            ['pause_outreach_campaign'],
            $settings->withoutDestructiveTools(['pause_outreach_campaign', 'delete_campaign']),
        );
    }

    public function test_delete_approvals_are_excluded_from_auto_launch(): void
    {
        $approval = new AiActionApproval([
            'tool' => 'delete_campaign',
            'payload' => [
                'type' => 'campaign_delete',
                'destructive' => true,
                'requires_explicit_approval' => true,
                'campaign_id' => 1,
            ],
        ]);

        $this->assertFalse(app(CommandCenterService::class)->isAutoLaunchApproval($approval));
    }

    public function test_delete_approval_labels_say_confirm_delete(): void
    {
        $labels = ApprovalActionLabels::for('delete_campaign', [
            'type' => 'campaign_delete',
        ]);

        $this->assertSame('Confirm Delete', $labels['approve']);
        $this->assertSame('Keep', $labels['reject']);
    }
}

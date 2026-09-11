<?php

namespace Tests\Unit\V2\Ai;

use App\Models\AiActionApproval;
use App\V2\Ai\Services\AiEmployeeSettingsService;
use App\V2\Ai\Services\CommandCenterService;
use App\V2\Ai\Services\DeleteCampaignCommandCenterService;
use App\V2\Ai\Support\ApprovalActionLabels;
use Tests\TestCase;

class DeleteCampaignApprovalGuardTest extends TestCase
{
    public function test_delete_tools_are_never_auto_executable(): void
    {
        $settings = app(AiEmployeeSettingsService::class);

        $this->assertTrue($settings->isDestructiveTool('delete_campaign'));
        $this->assertTrue($settings->isDestructiveTool('delete_resource'));
        $this->assertTrue($settings->isDestructiveTool('delete_something_else'));
        $this->assertFalse($settings->isDestructiveTool('pause_outreach_campaign'));

        $this->assertSame(
            ['pause_outreach_campaign'],
            $settings->withoutDestructiveTools(['pause_outreach_campaign', 'delete_campaign', 'delete_resource']),
        );
    }

    public function test_delete_approvals_are_excluded_from_auto_launch(): void
    {
        $campaignDelete = new AiActionApproval([
            'tool' => 'delete_campaign',
            'payload' => [
                'type' => 'campaign_delete',
                'destructive' => true,
                'requires_explicit_approval' => true,
                'campaign_id' => 1,
            ],
        ]);

        $resourceDelete = new AiActionApproval([
            'tool' => 'delete_resource',
            'payload' => [
                'type' => 'resource_delete',
                'kind' => 'lead_list',
                'destructive' => true,
                'requires_explicit_approval' => true,
                'resource_id' => 'abc123',
            ],
        ]);

        $cc = app(CommandCenterService::class);
        $this->assertFalse($cc->isAutoLaunchApproval($campaignDelete));
        $this->assertFalse($cc->isAutoLaunchApproval($resourceDelete));
    }

    public function test_delete_approval_labels_say_confirm_delete(): void
    {
        foreach (['delete_campaign', 'delete_resource'] as $tool) {
            $labels = ApprovalActionLabels::for($tool, [
                'type' => $tool === 'delete_resource' ? 'resource_delete' : 'campaign_delete',
            ]);

            $this->assertSame('Confirm Delete', $labels['approve']);
            $this->assertSame('Keep', $labels['reject']);
        }
    }

    public function test_normalize_kind_aliases(): void
    {
        $svc = app(DeleteCampaignCommandCenterService::class);

        $this->assertSame('outreach', $svc->normalizeKind('outreach_campaign'));
        $this->assertSame('linkedin', $svc->normalizeKind('linkedin_campaign'));
        $this->assertSame('lead_list', $svc->normalizeKind('audience'));
        $this->assertSame('content_post', $svc->normalizeKind('post'));
        $this->assertSame('inbox_conversation', $svc->normalizeKind('thread'));
        $this->assertSame('outreach_template', $svc->normalizeKind('template'));
    }

    public function test_list_created_today_method_exists(): void
    {
        $svc = app(DeleteCampaignCommandCenterService::class);
        $this->assertTrue(method_exists($svc, 'listDeletableCampaignsCreatedToday'));
        $ref = new \ReflectionMethod($svc, 'listDeletableOutreachCampaigns');
        $this->assertGreaterThanOrEqual(3, $ref->getNumberOfParameters());
    }
}

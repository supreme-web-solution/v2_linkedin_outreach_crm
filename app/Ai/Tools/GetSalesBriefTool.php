<?php

namespace App\Ai\Tools;

use App\Models\V2OutreachCampaign;
use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Outreach\OutreachCampaignStatsService;
use App\V2\Services\DashboardStatsService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetSalesBriefTool extends GatedTool
{
    public function toolName(): string
    {
        return 'get_sales_brief';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Read;
    }

    public function description(): Stringable|string
    {
        return 'Weekly-style sales snapshot: leads, campaigns, inbox attention, replies, and active pipeline.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'period' => $schema->string()->enum(['today', 'week', 'all'])->nullable(),
        ];
    }

    protected function run(Request $request): array
    {
        $user = $this->context->user;
        $orgId = $this->context->organizationId;
        $dashboard = app(DashboardStatsService::class)->forUser($user);

        $activeOutreach = V2OutreachCampaign::query()
            ->where('organization_id', $orgId)
            ->whereIn('status', ['active', 'running', 'paused'])
            ->latest('id')
            ->limit(5)
            ->get();

        $statsService = app(OutreachCampaignStatsService::class);
        $replies = 0;
        $targeted = 0;
        $activeSummaries = [];

        foreach ($activeOutreach as $campaign) {
            $stats = $statsService->statsFor($campaign);
            $by = $stats['by_status'] ?? [];
            $campaignTargeted = (int) ($stats['total_leads'] ?? 0);
            $campaignReplies = (int) ($by['replied'] ?? 0);
            $targeted += $campaignTargeted;
            $replies += $campaignReplies;

            $activeSummaries[] = [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'status' => $campaign->status,
                'targeted' => $campaignTargeted,
                'replies' => $campaignReplies,
                'reply_rate' => $stats['reply_rate'] ?? 0,
            ];
        }

        $replyRate = $targeted > 0 ? round(($replies / $targeted) * 100, 1) : 0.0;

        $brief = [
            'leads' => $dashboard['leads'],
            'linkedin_leads' => $dashboard['linkedin_leads'],
            'imported_leads' => $dashboard['imported_leads'],
            'campaigns_total' => $dashboard['campaigns'],
            'outreach_campaigns' => $dashboard['outreach_campaigns'],
            'linkedin_campaigns' => $dashboard['linkedin_campaigns'],
            'active_outreach' => $activeOutreach->count(),
            'messages_sent' => $dashboard['messages_sent'],
            'unread_conversations' => $dashboard['unread_conversations'],
            'active_call_prospects' => $dashboard['calls'],
            'outreach_targeted' => $targeted,
            'outreach_replies' => $replies,
            'outreach_reply_rate' => $replyRate,
        ];

        $lines = [
            'Sales brief:',
            "• Leads in CRM: {$brief['leads']} (LinkedIn {$brief['linkedin_leads']}, imported {$brief['imported_leads']})",
            "• Campaigns: {$brief['campaigns_total']} total ({$brief['outreach_campaigns']} outreach, {$brief['linkedin_campaigns']} LinkedIn)",
            "• Active outreach: {$brief['active_outreach']} — {$brief['outreach_replies']} replies from {$brief['outreach_targeted']} targeted ({$replyRate}%)",
            "• Inbox: {$brief['unread_conversations']} unread · {$brief['messages_sent']} messages sent",
        ];

        if ($brief['active_call_prospects'] > 0) {
            $lines[] = "• Call pipeline: {$brief['active_call_prospects']} active prospects";
        }

        if ($brief['unread_conversations'] > 0) {
            $lines[] = '→ Say "attention" to see who needs a reply.';
        }

        if ($brief['active_outreach'] === 0 && $brief['outreach_campaigns'] === 0) {
            $lines[] = '→ No outreach running yet — describe a goal and I can draft a plan.';
        }

        return [
            'period' => $request['period'] ?? 'all',
            'metrics' => $brief,
            'active_outreach' => $activeSummaries,
            'brief' => implode("\n", $lines),
        ];
    }
}

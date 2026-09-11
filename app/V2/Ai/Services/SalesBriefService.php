<?php

namespace App\V2\Ai\Services;

use App\Models\User;
use App\Models\V2OutreachCampaign;
use App\V2\Outreach\OutreachCampaignStatsService;
use App\V2\Services\AcquisitionFunnelService;
use App\V2\Services\DashboardStatsService;

class SalesBriefService
{
    public function __construct(
        private readonly DashboardStatsService $dashboardStats,
        private readonly OutreachCampaignStatsService $campaignStats,
        private readonly AcquisitionFunnelService $funnel,
        private readonly AttentionQueueService $attention,
    ) {}

    /**
     * @return array{brief:string, metrics:array<string,mixed>, acquisition_funnel:array<string,mixed>}
     */
    public function forUser(User $user, int $organizationId, string $period = 'all'): array
    {
        $dashboard = $this->dashboardStats->forUser($user);

        $activeOutreach = V2OutreachCampaign::query()
            ->where('organization_id', $organizationId)
            ->whereIn('status', ['active', 'running', 'paused', 'preparing', 'created'])
            ->latest('id')
            ->limit(5)
            ->get();

        $replies = 0;
        $targeted = 0;
        $activeSummaries = [];

        foreach ($activeOutreach as $campaign) {
            $stats = $this->campaignStats->statsFor($campaign);
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
        $funnel = $this->funnel->forUser($user, $period);

        $metrics = [
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
            'acquisition_funnel' => $funnel['summary'],
        ];

        return [
            'brief' => $this->formatBrief($metrics, $funnel['summary'], $activeSummaries, $user, $organizationId),
            'metrics' => $metrics,
            'acquisition_funnel' => $funnel,
            'active_outreach' => $activeSummaries,
        ];
    }

    public function todaySummary(User $user, int $organizationId): string
    {
        return $this->forUser($user, $organizationId, 'today')['brief'];
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @param  array<string, mixed>  $funnelSummary
     * @param  list<array<string, mixed>>  $activeSummaries
     */
    private function formatBrief(
        array $metrics,
        array $funnelSummary,
        array $activeSummaries,
        User $user,
        int $organizationId,
    ): string {
        $lines = [
            "Here's where things stand:",
            "• Leads: {$metrics['leads']} (LinkedIn {$metrics['linkedin_leads']}, imported {$metrics['imported_leads']})",
            "• Campaigns: {$metrics['campaigns_total']} total — {$metrics['active_outreach']} active outreach",
            "• Outreach: {$metrics['outreach_replies']} replies from {$metrics['outreach_targeted']} targeted ({$metrics['outreach_reply_rate']}%)",
            "• Inbox: {$metrics['unread_conversations']} unread · {$metrics['messages_sent']} messages sent",
        ];

        if ($metrics['active_call_prospects'] > 0) {
            $lines[] = "• Call pipeline: {$metrics['active_call_prospects']} active prospects";
        }

        if ((int) ($funnelSummary['targeted'] ?? 0) > 0) {
            $lines[] = 'Pipeline: '
                .($funnelSummary['targeted'] ?? 0).' targeted → '
                .($funnelSummary['contacted'] ?? 0).' contacted → '
                .($funnelSummary['responses'] ?? 0).' replies → '
                .($funnelSummary['qualified'] ?? 0).' qualified';
        }

        if ($activeSummaries !== []) {
            $lines[] = 'Active campaigns:';
            foreach (array_slice($activeSummaries, 0, 3) as $row) {
                $lines[] = '  - '.($row['name'] ?? 'Campaign').' ('.($row['status'] ?? 'unknown').', '
                    .($row['replies'] ?? 0).'/'.$row['targeted'].' replied)';
            }
        }

        if ($metrics['unread_conversations'] > 0) {
            $attention = $this->attention->forUser($user, $organizationId, 3);
            $lines[] = (string) ($attention['summary'] ?? 'Unread inbox threads need a look.');
        }

        if ($metrics['active_outreach'] === 0 && $metrics['outreach_campaigns'] === 0) {
            $lines[] = 'Nothing is running yet — tell me who you want to reach and I can start outreach.';
        } else {
            $lines[] = 'Want me to dig into inbox replies or campaign performance? Just ask.';
        }

        return implode("\n", $lines);
    }
}

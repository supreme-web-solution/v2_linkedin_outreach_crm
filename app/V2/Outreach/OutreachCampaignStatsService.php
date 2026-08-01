<?php

namespace App\V2\Outreach;

use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLeadProgress;
use App\Models\V2OutreachNodeEvent;
use Illuminate\Support\Facades\DB;

class OutreachCampaignStatsService
{
    public function __construct(
        private readonly OutreachSequenceResolver $resolver = new OutreachSequenceResolver(),
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function statsFor(V2OutreachCampaign $campaign): array
    {
        $statusCounts = $campaign->outreachLeads()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $total = array_sum($statusCounts);
        $replied = (int) ($statusCounts['replied'] ?? 0);
        $done = (int) ($statusCounts['done'] ?? 0);
        $running = (int) ($statusCounts['running'] ?? 0);
        $pending = (int) ($statusCounts['pending'] ?? 0);
        $error = (int) ($statusCounts['error'] ?? 0);

        $eventCounts = V2OutreachNodeEvent::query()
            ->where('outreach_campaign_id', $campaign->id)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $stepsCompleted = (int) ($eventCounts['completed'] ?? 0) + (int) ($eventCounts['sent'] ?? 0);
        $stepsFailed = (int) ($eventCounts['failed'] ?? 0);

        $byChannel = V2OutreachNodeEvent::query()
            ->where('outreach_campaign_id', $campaign->id)
            ->whereNotNull('channel')
            ->select('channel', DB::raw('count(*) as total'))
            ->groupBy('channel')
            ->pluck('total', 'channel')
            ->all();

        $completionRate = $total > 0 ? round((($done + $replied) / $total) * 100, 1) : 0.0;
        $replyRate = $total > 0 ? round(($replied / $total) * 100, 1) : 0.0;

        $inviteAcceptedCount = V2OutreachLeadProgress::query()
            ->where('outreach_campaign_id', $campaign->id)
            ->get()
            ->filter(function (V2OutreachLeadProgress $progress) {
                if ($progress->acceptance_status) {
                    return true;
                }
                $state = is_array($progress->channel_state) ? $progress->channel_state : [];

                return (bool) ($state['linkedin']['invite_accepted'] ?? false);
            })
            ->count();

        $inviteAcceptedRate = $total > 0 ? round(($inviteAcceptedCount / $total) * 100, 1) : 0.0;

        return [
            'total_leads' => $total,
            'by_status' => [
                'pending' => $pending,
                'running' => $running,
                'replied' => $replied,
                'done' => $done,
                'error' => $error,
                'skipped' => (int) ($statusCounts['skipped'] ?? 0),
            ],
            'completion_rate' => $completionRate,
            'reply_rate' => $replyRate,
            'invite_accepted_count' => $inviteAcceptedCount,
            'invite_accepted_rate' => $inviteAcceptedRate,
            'steps_completed' => $stepsCompleted,
            'steps_failed' => $stepsFailed,
            'events_by_status' => $eventCounts,
            'actions_by_channel' => $byChannel,
            'funnel' => $this->funnelFor($campaign, $total),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function funnelFor(V2OutreachCampaign $campaign, int $totalLeads): array
    {
        $nodes = is_array($campaign->node_model) ? $campaign->node_model : [];
        $flat = $this->resolver->flattenNodes($nodes);

        if ($flat === []) {
            return [];
        }

        $eventsByNode = V2OutreachNodeEvent::query()
            ->where('outreach_campaign_id', $campaign->id)
            ->whereNotNull('node_key')
            ->select('node_key', 'status', DB::raw('count(*) as total'))
            ->groupBy('node_key', 'status')
            ->get()
            ->groupBy('node_key');

        $funnel = [];
        foreach ($flat as $node) {
            $key = (int) ($node['key'] ?? 0);
            if ($key <= 0) {
                continue;
            }

            $nodeEvents = $eventsByNode->get($key, collect());
            $statusTotals = $nodeEvents->pluck('total', 'status');
            $completed = (int) ($statusTotals['completed'] ?? 0) + (int) ($statusTotals['sent'] ?? 0);
            $failed = (int) ($statusTotals['failed'] ?? 0);
            $waiting = (int) ($statusTotals['waiting'] ?? 0);
            $skipped = (int) ($statusTotals['skipped'] ?? 0);
            $conditionMet = (int) ($statusTotals['condition_met'] ?? 0);
            $reached = $completed + $failed + $waiting + $skipped + $conditionMet;

            $funnel[] = [
                'node_key' => $key,
                'label' => (string) ($node['label'] ?? 'Step '.$key),
                'type' => (string) ($node['type'] ?? 'action'),
                'channel' => $node['channel'] ?? null,
                'reached' => $reached,
                'completed' => $completed,
                'failed' => $failed,
                'waiting' => $waiting,
                'skipped' => $skipped,
                'conversion_rate' => $totalLeads > 0 ? round(($reached / $totalLeads) * 100, 1) : 0.0,
            ];
        }

        return $funnel;
    }
}

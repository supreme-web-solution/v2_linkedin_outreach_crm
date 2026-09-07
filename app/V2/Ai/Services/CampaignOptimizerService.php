<?php

namespace App\V2\Ai\Services;

use App\Models\V2OutreachCampaign;
use App\V2\Outreach\OutreachCampaignStatsService;
use App\V2\Outreach\OutreachSequenceResolver;

class CampaignOptimizerService
{
    public function __construct(
        private readonly OutreachCampaignStatsService $stats,
        private readonly OutreachSequenceResolver $resolver,
    ) {}

    /**
     * @return array{
     *     campaign_id: int,
     *     campaign_name: string,
     *     metrics: array<string, mixed>,
     *     suggestions: list<array{priority:string, title:string, detail:string, tool_hint?:string}>,
     *     summary: string
     * }
     */
    public function analyze(V2OutreachCampaign $campaign): array
    {
        $stats = $this->stats->statsFor($campaign);
        $nodes = is_array($campaign->node_model) ? $campaign->node_model : [];
        $funnel = $stats['funnel'] ?? [];

        $suggestions = $this->suggestionsFromStats($stats, $funnel, $nodes);

        $replyRate = (float) ($stats['reply_rate'] ?? 0);
        $errors = (int) ($stats['by_status']['error'] ?? 0);
        $total = (int) ($stats['total_leads'] ?? 0);

        $summary = $suggestions === []
            ? "Campaign #{$campaign->id} looks healthy at {$replyRate}% reply rate."
            : sprintf(
                'Campaign #%d has %d optimization suggestion(s). Reply rate %.1f%%, %d errors on %d leads.',
                $campaign->id,
                count($suggestions),
                $replyRate,
                $errors,
                $total,
            );

        return [
            'campaign_id' => $campaign->id,
            'campaign_name' => $campaign->name,
            'metrics' => [
                'total_leads' => $total,
                'reply_rate' => $replyRate,
                'completion_rate' => $stats['completion_rate'] ?? 0,
                'invite_accepted_rate' => $stats['invite_accepted_rate'] ?? 0,
                'errors' => $errors,
                'steps_failed' => $stats['steps_failed'] ?? 0,
            ],
            'suggestions' => $suggestions,
            'summary' => $summary,
            'outreach_url' => url('/outreach/'.$campaign->id),
        ];
    }

    /**
     * @param  array<string, mixed>  $stats
     * @param  list<array<string, mixed>>  $funnel
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array{priority:string, title:string, detail:string, tool_hint?:string}>
     */
    public function suggestionsFromStats(array $stats, array $funnel, array $nodes): array
    {
        $suggestions = [];
        $total = (int) ($stats['total_leads'] ?? 0);
        $replyRate = (float) ($stats['reply_rate'] ?? 0);
        $inviteRate = (float) ($stats['invite_accepted_rate'] ?? 0);
        $errors = (int) ($stats['by_status']['error'] ?? 0);
        $failedSteps = (int) ($stats['steps_failed'] ?? 0);

        if ($total === 0) {
            return [[
                'priority' => 'high',
                'title' => 'Attach lead lists',
                'detail' => 'No leads are synced yet. Attach a list and activate the campaign.',
                'tool_hint' => 'draft_campaign_plan',
            ]];
        }

        if ($errors > 0 || $failedSteps > 0) {
            $suggestions[] = [
                'priority' => 'high',
                'title' => 'Review delivery errors',
                'detail' => "{$errors} lead(s) in error, {$failedSteps} failed step(s). Check integrations and daily limits.",
                'tool_hint' => 'pause_outreach_campaign',
            ];
        }

        if ($replyRate < 3 && $total >= 20) {
            $suggestions[] = [
                'priority' => 'high',
                'title' => 'Low reply rate',
                'detail' => "Reply rate is {$replyRate}%. Test shorter follow-ups and evidence-grounded copy.",
                'tool_hint' => 'draft_personalized_message',
            ];
        }

        if ($inviteRate >= 15 && $replyRate < 5) {
            $suggestions[] = [
                'priority' => 'medium',
                'title' => 'Invites work — messages may not',
                'detail' => "Invite accept rate {$inviteRate}% but replies are low. Refresh message copy on the first DM step.",
                'tool_hint' => 'optimize_campaign',
            ];
        }

        if ($this->hasEmptyInviteNotes($nodes)) {
            $suggestions[] = [
                'priority' => 'medium',
                'title' => 'Add LinkedIn invite notes',
                'detail' => 'Connection requests without a note convert worse. Add a short personalized invite.',
            ];
        }

        $dropStep = $this->largestFunnelDrop($funnel);
        if ($dropStep !== null) {
            $suggestions[] = [
                'priority' => 'medium',
                'title' => 'Sequence drop-off at '.$dropStep['label'],
                'detail' => 'Only '.$dropStep['conversion_rate'].'% of leads reach this step. Consider shorten_waits or copy changes.',
                'tool_hint' => 'adjust_follow_up',
            ];
        }

        $channels = array_keys($stats['actions_by_channel'] ?? []);
        if (count($channels) === 1 && $replyRate < 5 && $total >= 15) {
            $suggestions[] = [
                'priority' => 'low',
                'title' => 'Try a second channel',
                'detail' => 'Single-channel sequence with weak replies — consider LinkedIn → email or WhatsApp follow-up.',
                'tool_hint' => 'propose_strategy',
            ];
        }

        return $suggestions;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    private function hasEmptyInviteNotes(array $nodes): bool
    {
        $flat = $this->resolver->flattenNodes($nodes);

        foreach ($flat as $node) {
            if (($node['action'] ?? '') === 'send_invite') {
                $message = trim((string) (data_get($node, 'config.message') ?? ''));

                if ($message === '') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $funnel
     * @return array{label:string, conversion_rate:float}|null
     */
    private function largestFunnelDrop(array $funnel): ?array
    {
        $worst = null;

        foreach ($funnel as $step) {
            if (($step['type'] ?? '') === 'end') {
                continue;
            }

            $rate = (float) ($step['conversion_rate'] ?? 100);
            if ($rate >= 40) {
                continue;
            }

            if ($worst === null || $rate < $worst['conversion_rate']) {
                $worst = [
                    'label' => (string) ($step['label'] ?? 'step'),
                    'conversion_rate' => $rate,
                ];
            }
        }

        return $worst;
    }
}

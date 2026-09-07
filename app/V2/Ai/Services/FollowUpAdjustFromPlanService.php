<?php

namespace App\V2\Ai\Services;

use App\Models\AiActionApproval;
use App\Models\User;
use App\Models\V2OutreachCampaign;

class FollowUpAdjustFromPlanService
{
    /**
     * @return array{message:string, campaign_id:int, outreach_url:string}
     */
    public function applyFromApproval(AiActionApproval $approval, User $user): array
    {
        $payload = $approval->payload ?? [];
        $campaignId = (int) ($payload['campaign_id'] ?? 0);
        $adjustment = (string) ($payload['adjustment'] ?? '');

        $campaign = V2OutreachCampaign::query()
            ->where('id', $campaignId)
            ->where('user_id', $user->id)
            ->where('organization_id', (int) $approval->organization_id)
            ->first();

        if (! $campaign) {
            throw new \RuntimeException("Campaign #{$campaignId} not found.");
        }

        $meta = is_array($campaign->meta) ? $campaign->meta : [];

        if ($adjustment === 'pause_on_reply') {
            $meta['ai_pause_on_reply_recommended'] = true;
            $meta['ai_pause_on_reply_at'] = now()->toIso8601String();
            $campaign->update(['meta' => $meta]);

            return [
                'message' => "Campaign #{$campaignId} flagged for pause-on-reply. Review sequence settings in the outreach builder.",
                'campaign_id' => $campaign->id,
                'outreach_url' => url('/outreach/'.$campaign->id),
            ];
        }

        $deltaDays = max(1, (int) ($payload['delta_days'] ?? 1));
        $extend = $adjustment === 'extend_waits';
        $nodes = is_array($campaign->node_model) ? $campaign->node_model : [];
        $updated = $this->adjustDelayNodes($nodes, $deltaDays, $extend);

        $meta['ai_last_sequence_adjustment'] = [
            'adjustment' => $adjustment,
            'delta_days' => $deltaDays,
            'applied_at' => now()->toIso8601String(),
            'approval_id' => $approval->id,
        ];

        $campaign->update([
            'node_model' => $updated,
            'meta' => $meta,
        ]);

        $verb = $extend ? 'extended' : 'shortened';

        return [
            'message' => "Campaign #{$campaignId} wait steps {$verb} by {$deltaDays} day(s).",
            'campaign_id' => $campaign->id,
            'outreach_url' => url('/outreach/'.$campaign->id),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $nodes
     * @return list<array<string,mixed>>
     */
    private function adjustDelayNodes(array $nodes, int $deltaDays, bool $extend): array
    {
        foreach ($nodes as $index => $node) {
            if (($node['type'] ?? '') === 'delay') {
                $current = max(1, (int) ($node['value'] ?? 1));
                $node['value'] = $extend
                    ? $current + $deltaDays
                    : max(1, $current - $deltaDays);
                $time = (string) ($node['time'] ?? 'days');
                $node['label'] = 'Wait '.$node['value'].' '.$time;
                $nodes[$index] = $node;

                continue;
            }

            if (($node['type'] ?? '') === 'condition' && is_array($node['branches'] ?? null)) {
                foreach ($node['branches'] as $branchKey => $branchNodes) {
                    if (is_array($branchNodes)) {
                        $node['branches'][$branchKey] = $this->adjustDelayNodes($branchNodes, $deltaDays, $extend);
                    }
                }
                $nodes[$index] = $node;
            }
        }

        return $nodes;
    }
}

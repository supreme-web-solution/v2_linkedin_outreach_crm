<?php

namespace App\V2\Ai\Services;

use App\Models\AiActionApproval;
use App\Models\User;
use Illuminate\Support\Carbon;

class OptimizeCampaignFromPlanService
{
    public function __construct(
        private readonly FollowUpAdjustFromPlanService $followUpAdjust,
    ) {}

    /**
     * @return array{message:string, campaign_id:int, outreach_url:string, auto_applied?:list<string>}
     */
    public function applyFromApproval(AiActionApproval $approval, User $user): array
    {
        $payload = $approval->payload ?? [];
        $campaignId = (int) ($payload['campaign_id'] ?? 0);

        $campaign = app(OutreachCampaignCommandService::class)->findOwned(
            $user,
            (int) $approval->organization_id,
            $campaignId,
        );

        if (! $campaign) {
            throw new \RuntimeException("Campaign #{$campaignId} not found.");
        }

        $suggestions = is_array($payload['suggestions'] ?? null) ? $payload['suggestions'] : [];
        $autoApplied = $this->autoApplyFollowUpAdjustments($approval, $user, $campaignId, $suggestions);

        $meta = is_array($campaign->meta) ? $campaign->meta : [];
        $meta['ai_optimization'] = [
            'suggestions' => $suggestions,
            'metrics' => $payload['metrics'] ?? [],
            'saved_at' => Carbon::now()->toIso8601String(),
            'approval_id' => $approval->id,
            'source' => 'command_center',
            'auto_applied' => $autoApplied,
        ];

        $campaign->update(['meta' => $meta]);

        $message = 'Optimization saved on campaign #'.$campaignId.'.';
        if ($autoApplied !== []) {
            $message .= ' Auto-applied: '.implode('; ', $autoApplied).'.';
        }

        return [
            'message' => $message,
            'campaign_id' => $campaign->id,
            'outreach_url' => url('/outreach/'.$campaign->id),
            'auto_applied' => $autoApplied,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $suggestions
     * @return list<string>
     */
    private function autoApplyFollowUpAdjustments(
        AiActionApproval $approval,
        User $user,
        int $campaignId,
        array $suggestions,
    ): array {
        $hasFollowUpHint = collect($suggestions)->contains(
            fn (array $s) => ($s['tool_hint'] ?? '') === 'adjust_follow_up',
        );

        if (! $hasFollowUpHint) {
            return [];
        }

        $synthetic = new AiActionApproval([
            'id' => $approval->id,
            'organization_id' => $approval->organization_id,
            'payload' => [
                'campaign_id' => $campaignId,
                'adjustment' => 'shorten_waits',
                'delta_days' => 2,
                'reason' => 'Auto-applied from optimize_campaign Launch (sequence drop-off).',
            ],
        ]);

        try {
            $result = $this->followUpAdjust->applyFromApproval($synthetic, $user);

            return [$result['message']];
        } catch (\Throwable $e) {
            report($e);

            return ['Follow-up auto-adjust skipped: '.$e->getMessage()];
        }
    }
}

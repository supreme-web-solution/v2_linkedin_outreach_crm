<?php

namespace App\V2\Ai\Services;

use App\Models\User;
use App\Models\V2OutreachCampaign;
use App\V2\Outreach\OutreachCampaignStatsService;
use App\V2\Services\UnifiedInboxReplyService;
use Illuminate\Support\Str;

class SalesManagerPlanBuilderService
{
    public function __construct(
        private readonly AttentionQueueService $attention,
        private readonly OutreachCampaignCommandService $campaigns,
        private readonly OutreachCampaignStatsService $stats,
        private readonly NurtureQueueService $nurture,
    ) {}

    /**
     * Build a multi-step sales manager plan:
     * pause underperformers, follow up hot inbox, nurture cold replies,
     * scale winners, activate strong drafts, shift channel mix hints.
     *
     * @return array<string, mixed>
     */
    public function build(
        User $user,
        int $organizationId,
        int $maxPause = 3,
        int $maxFollowUps = 7,
        int $maxNurture = 3,
        int $maxScale = 2,
        int $maxActivate = 2,
    ): array {
        $maxPause = max(0, min(10, $maxPause));
        $maxFollowUps = max(0, min(15, $maxFollowUps));
        $maxNurture = max(0, min(10, $maxNurture));
        $maxScale = max(0, min(5, $maxScale));
        $maxActivate = max(0, min(5, $maxActivate));

        $actions = [];
        $steps = [];

        foreach ($this->campaignsToPause($user, $organizationId, $maxPause) as $campaign) {
            $actions[] = [
                'type' => 'pause_campaign',
                'campaign_id' => $campaign['campaign_id'],
                'campaign_name' => $campaign['campaign_name'],
                'reason' => $campaign['reason'],
            ];
            $steps[] = 'Pause campaign #'.$campaign['campaign_id'].' ('.$campaign['reason'].')';
        }

        foreach ($this->followUpActions($user, $organizationId, $maxFollowUps) as $followUp) {
            $actions[] = $followUp;
            $steps[] = 'Send follow-up to '.$followUp['prospect_name'].' ('.$followUp['channel_label'].')';
        }

        foreach ($this->nurtureActions($user, $maxNurture) as $nurture) {
            $actions[] = $nurture;
            $steps[] = 'Move '.$nurture['prospect_name'].' to nurture ('.$nurture['reason'].')';
        }

        foreach ($this->campaignsToScale($user, $organizationId, $maxScale) as $scale) {
            $actions[] = $scale;
            $steps[] = 'Scale '.$scale['campaign_name'].' by '.$scale['percent'].'% ('.$scale['reason'].')';
        }

        foreach ($this->campaignsToActivate($user, $organizationId, $maxActivate) as $activate) {
            $actions[] = $activate;
            $steps[] = 'Activate '.$activate['campaign_name'].' ('.$activate['reason'].')';
        }

        foreach ($this->channelMixShifts($user, $organizationId, 2) as $shift) {
            $actions[] = $shift;
            $steps[] = 'Shift '.$shift['campaign_name'].' toward '.$shift['preferred_channels'].' ('.$shift['reason'].')';
        }

        $attention = $this->attention->forUser($user, $organizationId, 1);
        $hot = (int) ($attention['counts']['hot'] ?? 0);
        $needYou = (int) ($attention['counts']['need_you'] ?? 0);

        $goal = $actions === []
            ? 'Sales manager check-in — nothing urgent to execute right now'
            : 'Let AI execute '.$this->actionSummary($actions);

        return [
            'type' => 'sales_manager_execute',
            'goal' => $goal,
            'actions' => $actions,
            'counts' => [
                'pause_campaign' => $this->countType($actions, 'pause_campaign'),
                'draft_reply' => $this->countType($actions, 'draft_reply'),
                'move_to_nurture' => $this->countType($actions, 'move_to_nurture'),
                'scale_campaign' => $this->countType($actions, 'scale_campaign'),
                'activate_campaign' => $this->countType($actions, 'activate_campaign'),
                'shift_channel_mix' => $this->countType($actions, 'shift_channel_mix'),
                'hot_leads' => $hot,
                'need_you' => $needYou,
            ],
            'steps' => $steps !== [] ? $steps : ['No automated actions queued — review weekly brief in chat.'],
            'empty' => $actions === [],
        ];
    }

    /**
     * @return list<array{campaign_id:int, campaign_name:string, reason:string}>
     */
    private function campaignsToPause(User $user, int $organizationId, int $limit): array
    {
        if ($limit === 0) {
            return [];
        }

        $candidates = V2OutreachCampaign::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'running', 'preparing'])
            ->latest('id')
            ->limit(15)
            ->get();

        $scored = [];

        foreach ($candidates as $campaign) {
            $stats = $this->stats->statsFor($campaign);
            $errors = (int) ($stats['by_status']['error'] ?? 0);
            $failedSteps = (int) ($stats['steps_failed'] ?? 0);
            $replyRate = (float) ($stats['reply_rate'] ?? 0);
            $total = (int) ($stats['total_leads'] ?? 0);

            $score = 0;
            $reason = null;

            if ($errors >= 3 || $failedSteps >= 5) {
                $score = 100 + $errors;
                $reason = "{$errors} delivery error(s), {$failedSteps} failed step(s)";
            } elseif ($total >= 30 && $replyRate < 1.5) {
                $score = 50;
                $reason = "Low reply rate ({$replyRate}%) on {$total} leads";
            }

            if ($score > 0 && $reason !== null) {
                $scored[] = [
                    'score' => $score,
                    'campaign_id' => $campaign->id,
                    'campaign_name' => $campaign->name,
                    'reason' => $reason,
                ];
            }
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $limit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function followUpActions(User $user, int $organizationId, int $limit): array
    {
        if ($limit === 0) {
            return [];
        }

        $queue = $this->attention->forUser($user, $organizationId, $limit + 5);
        $replyService = app(UnifiedInboxReplyService::class);
        $actions = [];

        foreach ($queue['items'] as $item) {
            if (count($actions) >= $limit) {
                break;
            }

            if (! in_array($item['priority'] ?? '', ['hot', 'needs_judgment'], true)) {
                continue;
            }

            $intent = Str::lower((string) ($item['intent'] ?? ''));
            if (in_array($intent, ['not_interested', 'unsubscribe', 'maybe_later', 'nurture'], true)) {
                continue;
            }

            $conversationId = (int) ($item['conversation_id'] ?? 0);
            if ($conversationId <= 0) {
                continue;
            }

            try {
                $conversation = \App\Models\V2Conversation::query()
                    ->where('user_id', $user->id)
                    ->whereKey($conversationId)
                    ->firstOrFail();

                $draft = $replyService->draftReplyForConversation($user, $conversation);
            } catch (\Throwable) {
                continue;
            }

            $actions[] = [
                'type' => 'draft_reply',
                'conversation_id' => $conversationId,
                'prospect_name' => $draft['prospect_name'],
                'channel' => $draft['channel'],
                'channel_label' => $draft['channel_label'],
                'inbound_preview' => Str::limit($draft['inbound_preview'], 200, '…'),
                'draft_text' => $draft['draft'],
                'inbox_url' => $draft['inbox_url'],
                'priority' => $item['priority'],
                'intent' => $item['intent'] ?? null,
            ];
        }

        return $actions;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function nurtureActions(User $user, int $limit): array
    {
        if ($limit === 0) {
            return [];
        }

        $queue = $this->attention->forUser($user, (int) ($user->current_organization_id ?? 0), $limit + 8);
        $actions = [];

        foreach ($queue['items'] as $item) {
            if (count($actions) >= $limit) {
                break;
            }

            $intent = Str::lower((string) ($item['intent'] ?? ''));
            $priority = (string) ($item['priority'] ?? '');
            $isNurtureIntent = in_array($intent, ['not_interested', 'unsubscribe', 'maybe_later', 'nurture', 'timing'], true);
            $isLow = $priority === 'low_priority';

            if (! $isNurtureIntent && ! $isLow) {
                continue;
            }

            $conversationId = (int) ($item['conversation_id'] ?? 0);
            if ($conversationId <= 0) {
                continue;
            }

            $actions[] = [
                'type' => 'move_to_nurture',
                'conversation_id' => $conversationId,
                'prospect_name' => (string) ($item['prospect_name'] ?? 'Prospect'),
                'reason' => $isNurtureIntent
                    ? 'Intent: '.$intent
                    : 'Low-priority inbox — park for later follow-up',
                'follow_up_days' => $intent === 'not_interested' ? 120 : 90,
            ];
        }

        if (count($actions) < $limit) {
            $due = $this->nurture->dueForFollowUp($user, $limit);
            foreach ($due['items'] as $item) {
                if (count($actions) >= $limit) {
                    break;
                }
                // Due nurture leads are already in nurture — skip re-move.
            }
        }

        return $actions;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function campaignsToScale(User $user, int $organizationId, int $limit): array
    {
        if ($limit === 0) {
            return [];
        }

        $candidates = V2OutreachCampaign::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'running'])
            ->latest('id')
            ->limit(15)
            ->get();

        $scored = [];
        foreach ($candidates as $campaign) {
            $stats = $this->stats->statsFor($campaign);
            $replyRate = (float) ($stats['reply_rate'] ?? 0);
            $total = (int) ($stats['total_leads'] ?? 0);
            $meta = is_array($campaign->meta) ? $campaign->meta : [];
            $alreadyScaled = (int) ($meta['ai_volume_scale_percent'] ?? 0);

            if ($total < 20 || $replyRate < 4 || $alreadyScaled >= 20) {
                continue;
            }

            $scored[] = [
                'type' => 'scale_campaign',
                'campaign_id' => $campaign->id,
                'campaign_name' => $campaign->name,
                'percent' => 20,
                'reason' => "Healthy {$replyRate}% reply rate on {$total} leads",
                'score' => $replyRate,
            ];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_map(function (array $row) {
            unset($row['score']);

            return $row;
        }, array_slice($scored, 0, $limit));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function campaignsToActivate(User $user, int $organizationId, int $limit): array
    {
        if ($limit === 0) {
            return [];
        }

        $drafts = V2OutreachCampaign::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->where('status', 'draft')
            ->whereHas('outreachLists')
            ->latest('id')
            ->limit($limit)
            ->get();

        return $drafts->map(fn (V2OutreachCampaign $c) => [
            'type' => 'activate_campaign',
            'campaign_id' => $c->id,
            'campaign_name' => $c->name,
            'reason' => 'Draft with audience attached — ready to run',
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function channelMixShifts(User $user, int $organizationId, int $limit): array
    {
        $candidates = V2OutreachCampaign::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'running', 'draft'])
            ->latest('id')
            ->limit(10)
            ->get();

        $actions = [];
        foreach ($candidates as $campaign) {
            if (count($actions) >= $limit) {
                break;
            }

            $analysis = app(CampaignOptimizerService::class)->analyze($campaign);
            $hint = collect($analysis['suggestions'] ?? [])->first(
                fn ($s) => str_contains(Str::lower((string) ($s['title'] ?? '')), 'channel')
                    || str_contains(Str::lower((string) ($s['detail'] ?? '')), 'email')
                    || (($s['tool_hint'] ?? '') === 'optimize_campaign'),
            );

            if (! $hint) {
                continue;
            }

            $meta = is_array($campaign->meta) ? $campaign->meta : [];
            if (! empty($meta['ai_preferred_channels_shift'])) {
                continue;
            }

            $preferred = Str::contains(Str::lower((string) ($hint['detail'] ?? '')), 'instagram')
                ? 'LinkedIn + Instagram'
                : 'LinkedIn + Email';

            $actions[] = [
                'type' => 'shift_channel_mix',
                'campaign_id' => $campaign->id,
                'campaign_name' => $campaign->name,
                'preferred_channels' => $preferred,
                'reason' => (string) ($hint['title'] ?? 'Optimizer channel hint'),
            ];
        }

        return $actions;
    }

    /**
     * @param  list<array<string, mixed>>  $actions
     */
    private function countType(array $actions, string $type): int
    {
        return count(array_filter($actions, fn ($a) => ($a['type'] ?? '') === $type));
    }

    /**
     * @param  list<array<string, mixed>>  $actions
     */
    private function actionSummary(array $actions): string
    {
        $parts = [];
        $map = [
            'draft_reply' => 'follow up %d',
            'move_to_nurture' => 'nurture %d',
            'pause_campaign' => 'pause %d',
            'scale_campaign' => 'scale %d',
            'activate_campaign' => 'activate %d',
            'shift_channel_mix' => 'shift channel mix on %d',
        ];

        foreach ($map as $type => $template) {
            $count = $this->countType($actions, $type);
            if ($count > 0) {
                $parts[] = sprintf($template, $count);
            }
        }

        return implode(', ', $parts);
    }
}

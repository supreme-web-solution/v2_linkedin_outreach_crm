<?php

namespace App\V2\Ai\Services;

use App\Models\AiConversation;
use App\Models\User;
use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachList;
use App\V2\Ai\Enums\AiAutonomyLevel;
use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Support\PlanLeadList;

/**
 * After parallel discovery, stage one conversation-first campaign per platform list.
 * Does not rely on the model remembering to call draft_campaign_plan twice.
 */
class MultiChannelCampaignStagingService
{
    public function __construct(
        private readonly ActionApprovalService $approvals,
        private readonly CommandCenterService $commandCenter,
        private readonly AiEmployeeSettingsService $settings,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $lists
     * @return list<array<string, mixed>>
     */
    public function stage(
        User $user,
        int $organizationId,
        string $goal,
        array $lists,
        ?AiConversation $conversation = null,
    ): array {
        $ready = [];
        foreach ($lists as $list) {
            if (! is_array($list)) {
                continue;
            }
            $hash = trim((string) ($list['list_hash'] ?? ''));
            $channel = strtolower(trim((string) ($list['primary_channel'] ?? $list['platform'] ?? '')));
            if ($hash === '' || ! in_array($channel, ['linkedin', 'instagram'], true)) {
                continue;
            }
            if ($this->campaignExistsForList($hash)) {
                continue;
            }
            $ready[$channel.':'.$hash] = $list;
        }

        if ($ready === []) {
            return [];
        }

        $this->approvals->supersedePending($user, $organizationId);

        $settings = $this->settings->for($user, $organizationId);
        $autonomy = AiAutonomyLevel::tryFrom((int) $settings->autonomy_level) ?? AiAutonomyLevel::Assisted;
        $setupOnly = app(UserTurnIntentService::class)->wantsCampaignSetupOnly($goal);
        $autoLaunch = $autonomy->value >= AiAutonomyLevel::Autopilot->value && ! $setupOnly;

        $staged = [];
        foreach ($ready as $list) {
            $channel = strtolower((string) ($list['primary_channel'] ?? $list['platform']));
            $plan = $this->planForList($goal, $list, $channel, $setupOnly);
            $approval = $this->approvals->createPendingWithoutSupersede(
                $user,
                $organizationId,
                'draft_campaign_plan',
                AiToolPermission::Prepare,
                $plan,
                $conversation,
            );

            $row = [
                'channel' => $channel,
                'approval_id' => $approval->id,
                'list_hash' => $list['list_hash'],
                'list_name' => $list['list_name'] ?? null,
                'total_leads' => $list['total_leads'] ?? null,
                'first_message' => 'Written per lead after research — not a shared template.',
            ];

            if ($autoLaunch) {
                $this->commandCenter->handleControlCommand(
                    $user,
                    $organizationId,
                    'LAUNCH '.$approval->id,
                );
            }

            $fresh = $approval->fresh() ?? $approval;
            $campaignId = (int) data_get($fresh->result, 'outreach_campaign_id');
            $campaign = $campaignId > 0 ? V2OutreachCampaign::query()->find($campaignId) : null;
            $url = $campaign ? url('/outreach/'.$campaign->id) : null;
            $status = $campaign?->status ?? ($autoLaunch ? 'unknown' : 'awaiting_review');

            $row = [
                'channel' => $channel,
                'approval_id' => $approval->id,
                'campaign_id' => $campaignId > 0 ? $campaignId : null,
                'name' => $campaign?->name ?? ($plan['campaign_name'] ?? $channel),
                'url' => $url,
                'status' => $status,
                'status_label' => $this->statusLabel((string) $status, $autoLaunch, $setupOnly),
                'list_hash' => $list['list_hash'],
                'list_name' => $list['list_name'] ?? null,
                'total_leads' => $list['total_leads'] ?? null,
                'first_message' => 'Written per lead after research — not a shared template.',
            ];

            $staged[] = $row;
        }

        return $staged;
    }

    private function statusLabel(string $status, bool $autoLaunch, bool $setupOnly = false): string
    {
        if ($setupOnly && ! $autoLaunch) {
            return 'staged for Review & Launch — not sent (you asked not to launch yet)';
        }

        return match ($status) {
            'active', 'running' => 'launched — outreach will send as each step is ready',
            'paused' => 'paused — not sending until you launch it',
            'draft' => $autoLaunch
                ? 'created, but not active yet'
                : 'ready in Review & Launch',
            default => $autoLaunch ? 'created' : 'ready in Review & Launch',
        };
    }

    /**
     * @param  array<string, mixed>  $list
     * @return array<string, mixed>
     */
    private function planForList(string $goal, array $list, string $channel, bool $setupOnly = false): array
    {
        $count = max(1, (int) ($list['total_leads'] ?? 1));
        $firstDegree = (bool) ($list['first_degree_only'] ?? false)
            || (bool) preg_match('/\b1st\b|1st°|first[- ]degree/i', (string) ($list['list_name'] ?? ''));
        $label = $channel === 'instagram' ? 'Instagram' : 'LinkedIn';
        $theme = $this->themeFromGoal($goal);
        $name = $theme.' · '.$label.' ('.$count.')';

        $sequence = $channel === 'instagram'
            ? [
                'Instagram DM — personalized after research, no pitch',
                'Wait 4 days',
                'Light follow-up if no reply',
                'Pause on reply — handle in inbox',
            ]
            : ($firstDegree
                ? [
                    'LinkedIn message — personalized after research, no invite',
                    'Wait 4 days',
                    'Light follow-up if no reply',
                    'Pause on reply — handle in inbox',
                ]
                : [
                    'Send Invite (empty note)',
                    'After acceptance',
                    'First LinkedIn message — personalized after research',
                    'Wait 4 days',
                    'Light follow-up if no reply',
                    'Pause on reply — handle in inbox',
                ]);

        $plan = [
            'type' => 'campaign',
            'goal' => $goal,
            'campaign_name' => $name,
            'audience' => (string) ($list['list_name'] ?? $label.' prospects'),
            'icp_notes' => $goal,
            'target_count' => $count,
            'preferred_channels' => $label,
            'channels' => $label,
            'primary_channel' => $channel,
            'single_channel_only' => true,
            'follow_up_days' => 4,
            'source' => 'parallel discovery',
            'pause_on_reply' => true,
            'auto_reply_enabled' => false,
            'first_degree_only' => $firstDegree && $channel === 'linkedin',
            'network_degree' => $firstDegree ? '1st' : null,
            'one_shot' => false,
            'message' => '',
            'personalize_before_send' => true,
            'sequence' => $sequence,
            'steps' => [
                'One platform only: '.$label,
                'First message is written per lead after research (company, profile, site) — not a shared template',
                $channel === 'linkedin' && ! $firstDegree
                    ? 'Blank invite first. First DM only after they accept.'
                    : 'Do not send until the first message is personalized for that person.',
                'Goal of the first message: earn a reply, do not pitch.',
            ],
            'status' => 'awaiting_review',
        ];

        if ($setupOnly) {
            $plan['setup_only'] = true;
        }

        return PlanLeadList::merge(
            $plan,
            (string) $list['list_hash'],
            (string) ($list['list_src'] ?? ($channel === 'instagram' ? 'csv' : 'sn')),
            (string) ($list['list_name'] ?? $name),
        );
    }

    private function themeFromGoal(string $goal): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $goal) ?? '');
        if (preg_match('/conversation-first/i', $text)) {
            return 'Conversation-first';
        }

        $text = preg_replace('/\b(find|my|ideal|customers|then|start|just|outreach)\b/i', ' ', $text) ?? $text;
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if ($text === '') {
            return 'Conversation-first';
        }

        return \Illuminate\Support\Str::limit($text, 36, '');
    }

    private function campaignExistsForList(string $listHash): bool
    {
        return V2OutreachList::query()->where('list_hash', $listHash)->exists();
    }
}

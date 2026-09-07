<?php

namespace App\V2\Ai\Services;

use App\Models\AiActionApproval;
use App\Models\User;
use App\Models\V2Conversation;
use App\Models\V2OutreachCampaign;
use App\V2\Services\UnifiedInboxReplyService;

class ExecuteSalesPlanFromPlanService
{
    public function __construct(
        private readonly OutreachCampaignCommandService $campaigns,
        private readonly LeadNurtureCommandCenterService $nurture,
    ) {}

    /**
     * @return array{message:string, results:list<array<string,mixed>>}
     */
    public function applyFromApproval(AiActionApproval $approval, User $user): array
    {
        $payload = $approval->payload ?? [];
        $actions = is_array($payload['actions'] ?? null) ? $payload['actions'] : [];
        $orgId = (int) $approval->organization_id;

        if ($actions === []) {
            throw new \InvalidArgumentException('No actions in sales manager plan.');
        }

        $results = [];

        foreach ($actions as $action) {
            $type = (string) ($action['type'] ?? '');

            $results[] = match ($type) {
                'pause_campaign' => $this->pauseCampaign($user, $orgId, $action),
                'draft_reply' => $this->sendDraftReply($user, $action),
                'move_to_nurture' => $this->moveToNurture($user, $action),
                'scale_campaign' => $this->scaleCampaign($user, $orgId, $action),
                'activate_campaign' => $this->activateCampaign($user, $orgId, $action),
                'shift_channel_mix' => $this->shiftChannelMix($user, $orgId, $action),
                default => [
                    'type' => $type !== '' ? $type : 'unknown',
                    'ok' => false,
                    'message' => 'Unsupported action type.',
                ],
            };
        }

        $approval->update([
            'result' => [
                'status' => 'executed',
                'results' => $results,
            ],
            'status' => 'executed',
        ]);

        return [
            'message' => 'Executed sales plan: '.$this->summarize($results).'.',
            'results' => $results,
        ];
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    private function pauseCampaign(User $user, int $orgId, array $action): array
    {
        $campaignId = (int) ($action['campaign_id'] ?? 0);
        $result = $this->campaigns->pause($user, $orgId, $campaignId);

        return [
            'type' => 'pause_campaign',
            'campaign_id' => $campaignId,
            'ok' => $result['ok'],
            'message' => $result['message'],
        ];
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    private function sendDraftReply(User $user, array $action): array
    {
        $conversationId = (int) ($action['conversation_id'] ?? 0);
        $draft = trim((string) ($action['draft_text'] ?? ''));

        if ($conversationId <= 0 || $draft === '') {
            return [
                'type' => 'draft_reply',
                'conversation_id' => $conversationId,
                'ok' => false,
                'message' => 'Missing conversation or draft.',
            ];
        }

        try {
            $conversation = V2Conversation::query()
                ->where('user_id', $user->id)
                ->whereKey($conversationId)
                ->firstOrFail();

            $sent = app(UnifiedInboxReplyService::class)->sendApprovedReply($user, $conversation, $draft);

            return [
                'type' => 'draft_reply',
                'conversation_id' => $conversationId,
                'ok' => true,
                'message' => 'Reply sent to '.($action['prospect_name'] ?? 'prospect').'.',
                'v2_message_id' => $sent->id,
                'inbox_url' => (string) ($action['inbox_url'] ?? url('/inbox')),
            ];
        } catch (\Throwable $e) {
            report($e);

            return [
                'type' => 'draft_reply',
                'conversation_id' => $conversationId,
                'ok' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    private function moveToNurture(User $user, array $action): array
    {
        try {
            $result = $this->nurture->moveToNurture(
                user: $user,
                conversationId: (int) ($action['conversation_id'] ?? 0) ?: null,
                outreachLeadId: isset($action['outreach_lead_id']) ? (int) $action['outreach_lead_id'] : null,
                followUpDays: (int) ($action['follow_up_days'] ?? 90),
                reason: (string) ($action['reason'] ?? 'Sales manager batch nurture'),
            );

            return [
                'type' => 'move_to_nurture',
                'conversation_id' => $action['conversation_id'] ?? null,
                'ok' => true,
                'message' => $result['message'] ?? 'Moved to nurture.',
            ];
        } catch (\Throwable $e) {
            return [
                'type' => 'move_to_nurture',
                'ok' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    private function scaleCampaign(User $user, int $orgId, array $action): array
    {
        $campaignId = (int) ($action['campaign_id'] ?? 0);
        $percent = max(5, min(50, (int) ($action['percent'] ?? 20)));
        $campaign = $this->campaigns->findOwned($user, $orgId, $campaignId);

        if (! $campaign) {
            return [
                'type' => 'scale_campaign',
                'campaign_id' => $campaignId,
                'ok' => false,
                'message' => "Campaign #{$campaignId} not found.",
            ];
        }

        $meta = is_array($campaign->meta) ? $campaign->meta : [];
        $meta['ai_volume_scale_percent'] = $percent;
        $meta['ai_volume_scaled_at'] = now()->toIso8601String();
        $meta['ai_volume_scale_reason'] = (string) ($action['reason'] ?? '');

        // Shorten waits slightly so the sequence moves more volume through.
        $nodes = is_array($campaign->node_model) ? $campaign->node_model : [];
        $campaign->forceFill([
            'meta' => $meta,
            'node_model' => $this->shortenWaits($nodes, 1),
        ])->save();

        return [
            'type' => 'scale_campaign',
            'campaign_id' => $campaignId,
            'ok' => true,
            'message' => "Scaled #{$campaignId} by ~{$percent}% (waits shortened, volume flag set).",
        ];
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    private function activateCampaign(User $user, int $orgId, array $action): array
    {
        $campaignId = (int) ($action['campaign_id'] ?? 0);
        $result = $this->campaigns->activate($user, $orgId, $campaignId);

        return [
            'type' => 'activate_campaign',
            'campaign_id' => $campaignId,
            'ok' => $result['ok'],
            'message' => $result['message'],
        ];
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    private function shiftChannelMix(User $user, int $orgId, array $action): array
    {
        $campaignId = (int) ($action['campaign_id'] ?? 0);
        $campaign = $this->campaigns->findOwned($user, $orgId, $campaignId);

        if (! $campaign) {
            return [
                'type' => 'shift_channel_mix',
                'campaign_id' => $campaignId,
                'ok' => false,
                'message' => "Campaign #{$campaignId} not found.",
            ];
        }

        $preferred = trim((string) ($action['preferred_channels'] ?? 'LinkedIn + Email'));
        $meta = is_array($campaign->meta) ? $campaign->meta : [];
        $meta['ai_preferred_channels_shift'] = $preferred;
        $meta['ai_channel_mix_shifted_at'] = now()->toIso8601String();
        $meta['ai_channel_mix_reason'] = (string) ($action['reason'] ?? '');

        $planProbe = ['preferred_channels' => $preferred];
        $templateType = app(CampaignDraftFromPlanService::class)->resolveTemplateType($planProbe);
        $templates = V2OutreachCampaign::templates();
        $nodeModel = $templates[$templateType]['node_model'] ?? null;

        $updates = ['meta' => $meta];
        if (is_array($nodeModel) && $campaign->status === 'draft') {
            $updates['template_type'] = $templateType;
            $updates['node_model'] = $nodeModel;
        }

        $campaign->forceFill($updates)->save();

        return [
            'type' => 'shift_channel_mix',
            'campaign_id' => $campaignId,
            'ok' => true,
            'message' => $campaign->status === 'draft'
                ? "Shifted draft #{$campaignId} to {$preferred}."
                : "Recorded channel mix preference {$preferred} on #{$campaignId} (running campaigns keep current sequence; apply on next draft).",
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $nodes
     * @return list<array<string,mixed>>
     */
    private function shortenWaits(array $nodes, int $deltaDays): array
    {
        foreach ($nodes as $index => $node) {
            if (($node['type'] ?? '') === 'delay') {
                $current = max(1, (int) ($node['value'] ?? 1));
                $node['value'] = max(1, $current - $deltaDays);
                $time = (string) ($node['time'] ?? 'days');
                $node['label'] = 'Wait '.$node['value'].' '.$time;
                $nodes[$index] = $node;
            }
        }

        return $nodes;
    }

    /**
     * @param  list<array<string,mixed>>  $results
     */
    private function summarize(array $results): string
    {
        $ok = fn (string $type) => count(array_filter(
            $results,
            fn ($r) => ($r['type'] ?? '') === $type && ($r['ok'] ?? false),
        ));

        $parts = array_filter([
            $ok('pause_campaign') > 0 ? "paused {$ok('pause_campaign')}" : null,
            $ok('draft_reply') > 0 ? "followed up {$ok('draft_reply')}" : null,
            $ok('move_to_nurture') > 0 ? "nurtured {$ok('move_to_nurture')}" : null,
            $ok('scale_campaign') > 0 ? "scaled {$ok('scale_campaign')}" : null,
            $ok('activate_campaign') > 0 ? "activated {$ok('activate_campaign')}" : null,
            $ok('shift_channel_mix') > 0 ? "shifted {$ok('shift_channel_mix')} channel mix" : null,
        ]);

        return $parts !== [] ? implode(', ', $parts) : 'no successful steps';
    }
}

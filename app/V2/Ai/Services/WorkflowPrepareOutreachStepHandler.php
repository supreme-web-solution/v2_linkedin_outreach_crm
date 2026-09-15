<?php

namespace App\V2\Ai\Services;

use App\Models\AiConversation;
use App\Models\User;
use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Support\AudienceCommitment;
use App\V2\Ai\Support\PlanLeadList;
use Illuminate\Support\Str;

/**
 * Stages a conversation-first campaign plan for Review & Launch on behalf of the workflow runtime.
 */
class WorkflowPrepareOutreachStepHandler
{
    public function __construct(
        private readonly ActionApprovalService $approvals,
        private readonly ProspectListMatchService $listMatch,
        private readonly MultiChannelCampaignStagingService $multiChannelStaging,
        private readonly PlanContentService $planContent,
        private readonly ProspectAudienceResolverService $audienceResolver,
        private readonly PlanFunnelService $funnel,
    ) {}

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function execute(
        User $user,
        int $organizationId,
        array $plan,
        array $arguments,
        int $workflowRunId,
        ?int $conversationId = null,
    ): array {
        if (\App\V2\Ai\Support\SingleRecipientTurnGuard::matches($plan)) {
            return [
                'step_type' => 'prepare_outreach',
                'prepared' => false,
                'blocked' => true,
                'message' => 'Single-recipient cold outbound must use draft_cold_outbound — list discovery outreach was blocked.',
                'approval_ids' => [],
                'eligible_count' => 0,
                'multi_channel' => false,
            ];
        }

        $stateEval = is_array($plan['state_evaluation'] ?? null) ? $plan['state_evaluation'] : [];
        $segment = trim((string) ($plan['objective']['segment'] ?? $plan['constraints']['target_segment'] ?? 'prospects'));
        $setupOnly = (bool) ($arguments['setup_only'] ?? false)
            || (string) ($plan['required_outcome'] ?? '') === 'setup_only';

        $discoveryLists = $this->usableDiscoveryLists(
            $user,
            is_array($arguments['discovery_lists'] ?? null) ? $arguments['discovery_lists'] : [],
        );
        $conversation = $conversationId ? AiConversation::query()->find($conversationId) : null;
        $goal = $this->goalFromPlan($plan, $segment);

        $runMeta = [
            'discovery_lists' => $discoveryLists,
            'cumulative_candidate_delta' => array_sum(array_map(
                fn (array $row) => (int) ($row['total_leads'] ?? 0),
                $discoveryLists,
            )),
            'workflow_run_id' => $workflowRunId,
        ];
        $eligible = max(1, AudienceCommitment::boundCount($plan, $runMeta, $stateEval));
        if (isset($arguments['eligible_count']) && (int) $arguments['eligible_count'] > 0) {
            $eligible = max(1, (int) $arguments['eligible_count']);
        }

        if (count($discoveryLists) >= 2) {
            $staged = $this->multiChannelStaging->stage(
                $user,
                $organizationId,
                $goal,
                $discoveryLists,
                $conversation,
                $setupOnly,
            );

            if ($staged === []) {
                throw new \RuntimeException('Could not stage multi-channel outreach from discovery lists.');
            }

            $approvalIds = array_values(array_filter(array_map(
                fn (array $row) => (int) ($row['approval_id'] ?? 0),
                $staged,
            )));

            foreach ($approvalIds as $approvalId) {
                \App\Models\AiActionApproval::query()
                    ->where('id', $approvalId)
                    ->update(['workflow_run_id' => $workflowRunId]);
            }

            return [
                'step_type' => 'prepare_outreach',
                'prepared' => true,
                'setup_only' => $setupOnly,
                'approval_id' => $approvalIds[0] ?? null,
                'approval_ids' => $approvalIds,
                'staged_campaigns' => $staged,
                'eligible_count' => $eligible,
                'multi_channel' => true,
            ];
        }

        $listHash = trim((string) ($arguments['list_hash'] ?? ''));
        if (count($discoveryLists) === 1) {
            $list = $discoveryLists[0];
            $provenance = AudienceCommitment::PROVENANCE_DISCOVERED_THIS_RUN;
        } elseif ($listHash !== '') {
            $list = [
                'list_hash' => $listHash,
                'list_src' => (string) ($arguments['list_src'] ?? 'sn'),
                'list_name' => (string) ($arguments['list_name'] ?? $segment),
            ];
            $provenance = AudienceCommitment::wantsExplicitReuse($plan)
                ? AudienceCommitment::PROVENANCE_EXPLICIT_USER
                : AudienceCommitment::PROVENANCE_DISCOVERED_THIS_RUN;
        } else {
            // Token/name match only — never size-only archive grab.
            $matched = $this->listMatch->matchForSegment(
                $user->id,
                $segment !== '' ? $segment : null,
                1,
                allowSizeFallback: false,
            );
            $list = $matched[0] ?? null;

            if ($list === null) {
                throw new \RuntimeException(
                    AudienceCommitment::requiresDiscoverThisRun($plan)
                        ? 'No prospect list from this run to stage outreach. Discover prospects first.'
                        : 'No matching prospect list available to stage outreach.'
                );
            }

            // Quantified discover-this-run must not silently bind an old list unless this
            // run already discovered into it (handled above via discovery_lists / list_hash).
            if (AudienceCommitment::requiresDiscoverThisRun($plan) && $discoveryLists === []) {
                throw new \RuntimeException('No prospect list from this run to stage outreach. Discover prospects first.');
            }

            $provenance = AudienceCommitment::PROVENANCE_REUSE_APPROVED;
        }

        $channel = $this->resolveOutreachChannel($plan, $list, $discoveryLists);
        $channelIntent = app(PlanChannelIntentService::class);
        $src = trim((string) ($list['list_src'] ?? $channelIntent->defaultListSrc($channel)));
        $hash = trim((string) ($list['list_hash'] ?? ''));
        $liveCount = $this->audienceResolver->liveLeadCount($user, $src, $hash);
        if ($hash === '' || $liveCount < 1) {
            throw new \RuntimeException('No people on that list yet. Find prospects first, then create the campaign.');
        }
        $list['list_src'] = $src;
        $requested = AudienceCommitment::requestedCount($plan);
        $eligible = min($eligible, $liveCount);
        if ($requested > 0) {
            $eligible = min($eligible, $requested);
        }
        $eligible = max(1, $eligible);
        $commitment = AudienceCommitment::build($plan, $runMeta, $list, $eligible, $provenance);
        $label = \App\V2\Outreach\OutreachChannelRegistry::channelLabel($channel);
        $theme = Str::limit(trim(preg_replace('/\s+/', ' ', $segment) ?: 'Conversation-first'), 36, '');
        $campaignName = $theme.' · '.$label.' ('.$eligible.')';

        $sequence = $channelIntent->conversationSequence($channel);

        $campaignPlan = [
            'type' => 'campaign',
            'goal' => $goal,
            'campaign_name' => $campaignName,
            'audience' => (string) ($list['list_name'] ?? $segment),
            'icp_notes' => $goal,
            'target_count' => $eligible,
            'audience_commitment' => $commitment,
            'preferred_channels' => $label,
            'channels' => $label,
            'primary_channel' => $channel,
            'single_channel_only' => true,
            'follow_up_days' => 4,
            'source' => 'workflow discovery',
            'pause_on_reply' => true,
            'auto_reply_enabled' => false,
            'one_shot' => false,
            'personalize_before_send' => true,
            'sequence' => $sequence,
            'steps' => [
                'First message earns a reply — no product pitch, no link, no demo ask',
                'Personalize per lead from profile/company research',
                'Goal: start a relevant sales conversation, not send volume',
            ],
            'status' => 'awaiting_review',
            'workflow_run_id' => $workflowRunId,
        ];

        if ($setupOnly) {
            $campaignPlan['setup_only'] = true;
        }

        $campaignPlan = $this->planContent->enrichCampaign($campaignPlan);
        $campaignPlan = PlanLeadList::merge(
            $campaignPlan,
            (string) $list['list_hash'],
            (string) ($list['list_src'] ?? 'sn'),
            (string) ($list['list_name'] ?? $campaignName),
        );
        $campaignPlan = $this->audienceResolver->enrichPlanWithAudience($user, $campaignPlan);
        $campaignPlan = $this->funnel->attachToPlan($campaignPlan, $user);

        $approval = $this->approvals->createPendingWithoutSupersede(
            $user,
            $organizationId,
            'draft_campaign_plan',
            AiToolPermission::Prepare,
            $campaignPlan,
            $conversation,
            $workflowRunId,
        );

        return [
            'step_type' => 'prepare_outreach',
            'prepared' => true,
            'setup_only' => $setupOnly,
            'approval_id' => $approval->id,
            'list_hash' => $list['list_hash'],
            'list_name' => $list['list_name'] ?? null,
            'eligible_count' => $eligible,
            'audience_commitment' => $commitment,
            'campaign_name' => $campaignName,
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>|null  $list
     * @param  list<array<string, mixed>>  $discoveryLists
     */
    private function resolveOutreachChannel(array $plan, ?array $list, array $discoveryLists): string
    {
        $fromList = $list !== null
            ? app(SingleChannelOutreachService::class)->primaryChannelFromList($list)
            : null;

        if ($fromList !== null) {
            return $fromList;
        }

        foreach ($discoveryLists as $row) {
            if (! is_array($row)) {
                continue;
            }
            $fromDiscovery = app(SingleChannelOutreachService::class)->primaryChannelFromList($row);
            if ($fromDiscovery !== null) {
                return $fromDiscovery;
            }
        }

        $channel = strtolower(trim((string) ($plan['constraints']['preferred_channel'] ?? '')));
        if (in_array($channel, ['linkedin', 'instagram', 'whatsapp', 'email', 'telegram', 'twitter'], true)) {
            return $channel;
        }

        return 'linkedin';
    }

    /**
     * @param  list<array<string, mixed>>  $lists
     * @return list<array<string, mixed>>
     */
    private function usableDiscoveryLists(User $user, array $lists): array
    {
        $usable = [];
        foreach ($lists as $list) {
            if (! is_array($list)) {
                continue;
            }
            $hash = trim((string) ($list['list_hash'] ?? ''));
            if ($hash === '') {
                continue;
            }
            $channel = strtolower(trim((string) ($list['primary_channel'] ?? $list['platform'] ?? '')));
            $src = trim((string) ($list['list_src'] ?? app(PlanChannelIntentService::class)->defaultListSrc($channel)));
            $live = $this->audienceResolver->liveLeadCount($user, $src, $hash);
            if ($live < 1) {
                continue;
            }
            $list['list_src'] = $src;
            $list['total_leads'] = $live;
            $usable[] = $list;
        }

        return $usable;
    }

    private function goalFromPlan(array $plan, string $segment): string
    {
        $measurable = is_array($plan['measurable_expectations'] ?? null) ? $plan['measurable_expectations'] : [];
        $count = (int) ($measurable['target_count'] ?? $plan['constraints']['target_count'] ?? 0);
        $segmentLabel = $segment !== '' ? $segment : 'target prospects';

        if ($count > 0) {
            return "Start relevant conversations with {$count} {$segmentLabel} — earn replies before introducing SociFusion.";
        }

        return "Start relevant conversations with {$segmentLabel} — earn replies before introducing SociFusion.";
    }
}

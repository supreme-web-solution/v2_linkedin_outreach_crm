<?php

namespace App\V2\Ai\Services;

use App\Models\AiConversation;
use App\Models\User;
use App\V2\Ai\Enums\AiToolPermission;
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
        $stateEval = is_array($plan['state_evaluation'] ?? null) ? $plan['state_evaluation'] : [];
        $segment = trim((string) ($plan['objective']['segment'] ?? $plan['constraints']['target_segment'] ?? 'prospects'));
        $eligible = max(1, (int) ($arguments['eligible_count'] ?? $stateEval['intersection_eligible_count'] ?? 1));
        $setupOnly = (bool) ($arguments['setup_only'] ?? false)
            || (string) ($plan['required_outcome'] ?? '') === 'setup_only';
        $channel = strtolower(trim((string) ($plan['constraints']['preferred_channel'] ?? 'linkedin')));
        if (! in_array($channel, ['linkedin', 'instagram', 'whatsapp', 'email'], true)) {
            $channel = 'linkedin';
        }

        $discoveryLists = is_array($arguments['discovery_lists'] ?? null) ? $arguments['discovery_lists'] : [];
        $conversation = $conversationId ? AiConversation::query()->find($conversationId) : null;
        $goal = $this->goalFromPlan($plan, $segment);

        if (count($discoveryLists) >= 2) {
            $staged = $this->multiChannelStaging->stage(
                $user,
                $organizationId,
                $goal,
                $discoveryLists,
                $conversation,
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
        if ($listHash !== '') {
            $list = [
                'list_hash' => $listHash,
                'list_src' => (string) ($arguments['list_src'] ?? 'sn'),
                'list_name' => (string) ($arguments['list_name'] ?? $segment),
            ];
        } else {
            $matched = $this->listMatch->matchForSegment($user->id, $segment !== '' ? $segment : null, 1);
            $list = $matched[0] ?? null;
        }
        if ($list === null) {
            throw new \RuntimeException('No prospect list available to stage outreach.');
        }

        $label = ucfirst($channel === 'instagram' ? 'Instagram' : ($channel === 'whatsapp' ? 'WhatsApp' : 'LinkedIn'));
        $theme = Str::limit(trim(preg_replace('/\s+/', ' ', $segment) ?: 'Conversation-first'), 36, '');
        $campaignName = $theme.' · '.$label.' ('.$eligible.')';

        $sequence = $channel === 'instagram'
            ? [
                'Instagram DM — personalized after research, no pitch',
                'Wait 4 days',
                'Light follow-up if no reply',
                'Pause on reply — handle in inbox',
            ]
            : [
                'Send Invite (empty note)',
                'After acceptance',
                'First message — earn a reply, do not pitch SociFusion yet',
                'Wait 4 days',
                'Light follow-up if no reply',
                'Pause on reply — handle in inbox',
            ];

        $campaignPlan = [
            'type' => 'campaign',
            'goal' => $goal,
            'campaign_name' => $campaignName,
            'audience' => (string) ($list['list_name'] ?? $segment),
            'icp_notes' => $goal,
            'target_count' => $eligible,
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
            'campaign_name' => $campaignName,
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     */
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

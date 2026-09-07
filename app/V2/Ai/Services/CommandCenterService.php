<?php

namespace App\V2\Ai\Services;

use App\Models\AiActionApproval;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use App\V2\Ai\Support\PlanLeadList;
use App\V2\Outreach\OutreachChannelGuard;
use App\V2\Outreach\OutreachChannelRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CommandCenterService
{
    public function __construct(
        private readonly ActionApprovalService $approvals,
        private readonly CampaignDraftFromPlanService $campaignDrafts,
        private readonly OutreachCampaignCommandService $campaignCommands,
        private readonly CompetitorHarvestFromPlanService $competitorHarvest,
        private readonly EnrichmentFromPlanService $enrichment,
        private readonly FollowUpAdjustFromPlanService $followUpAdjust,
        private readonly IcpFromPlanService $icpSave,
        private readonly NextBestActionFromPlanService $nextBestAction,
        private readonly OptimizeCampaignFromPlanService $optimizeCampaign,
        private readonly PersonalizedMessageFromPlanService $personalizedMessage,
        private readonly PostCallCrmFromPlanService $postCallCrm,
        private readonly QualifyLeadFromPlanService $qualifyLead,
        private readonly ReplySendFromPlanService $replySend,
        private readonly BookMeetingFromPlanService $bookMeeting,
        private readonly ExecuteSalesPlanFromPlanService $executeSalesPlan,
        private readonly OutreachChannelGuard $guard,
        private readonly ProspectAudienceResolverService $audienceResolver,
    ) {}

    /**
     * One open Command Center session per user + organization.
     */
    public function conversation(User $user, int $organizationId, ?int $channelIdentityId = null): AiConversation
    {
        $open = AiConversation::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->where('status', 'open')
            ->orderByDesc('id')
            ->first();

        if ($open) {
            if ($channelIdentityId && ! $open->channel_identity_id) {
                $open->update(['channel_identity_id' => $channelIdentityId]);
            }

            return $open;
        }

        return AiConversation::query()->create([
            'organization_id' => $organizationId,
            'user_id' => $user->id,
            'channel' => 'command_center',
            'channel_identity_id' => $channelIdentityId,
            'status' => 'open',
            'title' => 'Command Center',
        ]);
    }

    /**
     * Latest message window for Command Center chat (newest last).
     *
     * @return array{messages: Collection<int, AiMessage>, has_older: bool}
     */
    public function historyWindow(AiConversation $conversation, ?int $beforeId = null, int $limit = 50): array
    {
        $query = AiMessage::query()
            ->where('conversation_id', $conversation->id)
            ->whereIn('role', ['user', 'assistant']);

        if ($beforeId !== null && $beforeId > 0) {
            $query->where('id', '<', $beforeId);
        }

        $rows = $query
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->sortBy('id')
            ->values();

        $oldestId = $rows->first()?->id;
        $hasOlder = $oldestId
            ? AiMessage::query()
                ->where('conversation_id', $conversation->id)
                ->whereIn('role', ['user', 'assistant'])
                ->where('id', '<', $oldestId)
                ->exists()
            : false;

        return [
            'messages' => $rows,
            'has_older' => $hasOlder,
        ];
    }

    /**
     * @return Collection<int, AiMessage>
     */
    public function history(AiConversation $conversation, int $limit = 50): Collection
    {
        return $this->historyWindow($conversation, null, $limit)['messages'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function serializeMessages(Collection $messages): array
    {
        return $messages->map(fn (AiMessage $m) => [
            'id' => $m->id,
            'role' => $m->role,
            'content' => $m->content,
            'channel' => is_array($m->meta) ? ($m->meta['channel'] ?? null) : null,
            'created_at' => $m->created_at?->toIso8601String(),
        ])->values()->all();
    }

    /**
     * @return Collection<int, AiActionApproval>
     */
    public function pendingApprovals(User $user, int $organizationId): Collection
    {
        return AiActionApproval::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->limit(20)
            ->get();
    }

    public function formatPlanCard(array $plan, ?int $approvalId = null, string $surface = 'web'): string
    {
        $lines = ['Got it. Here\'s the plan:'];

        if (! empty($plan['goal'])) {
            $lines[] = '• Goal: '.$plan['goal'];
        }
        if (! empty($plan['icp']['summary'] ?? $plan['icp_notes'] ?? null)) {
            $lines[] = '• ICP: '.($plan['icp']['summary'] ?? $plan['icp_notes']);
        }
        if (! empty($plan['geography'])) {
            $lines[] = '• Geography: '.$plan['geography'];
        }
        if (! empty($plan['target_count'])) {
            $lines[] = '• Prospects: ~'.$plan['target_count'];
        }
        if (! empty($plan['preferred_channels'] ?? $plan['channels'] ?? null)) {
            $lines[] = '• Channels: '.($plan['preferred_channels'] ?? $plan['channels']);
        }
        if (! empty($plan['follow_up_days'])) {
            $lines[] = '• Follow-up: '.$plan['follow_up_days'].' days';
        }
        if (! empty($plan['list_name'] ?? null)) {
            $lines[] = '• Audience list: '.$plan['list_name'];
        } elseif (! empty($plan['audience_note'] ?? null)) {
            $lines[] = '• Audience list: '.$plan['audience_note'];
        } elseif (! empty($plan['list_hash'] ?? null)) {
            $lines[] = '• Audience list: '.$plan['list_hash'].' ('.($plan['list_src'] ?? 'list').')';
        } elseif (($plan['audience_status'] ?? '') === 'missing') {
            $lines[] = '• Audience: no matching list yet — find prospects before Launch';
        }
        if (! empty($plan['linkedin_url'])) {
            $lines[] = '• Harvest: '.$plan['linkedin_url'];
        }
        if (! empty($plan['prospect_name'])) {
            $lines[] = '• Prospect: '.$plan['prospect_name'].(! empty($plan['channel_label']) ? ' ('.$plan['channel_label'].')' : '');
        }
        if (! empty($plan['inbound_preview'])) {
            $lines[] = '• They said: '.$plan['inbound_preview'];
        }
        if (! empty($plan['draft_text'])) {
            $lines[] = '';
            $lines[] = 'Draft reply:';
            $lines[] = '"'.Str::limit((string) $plan['draft_text'], 500, '…').'"';
        }
        if (! empty($plan['counts']) && is_array($plan['counts']) && ($plan['type'] ?? '') === 'sales_manager_execute') {
            $c = $plan['counts'];
            $lines[] = '• Pause campaigns: '.($c['pause_campaign'] ?? 0);
            $lines[] = '• Follow-up replies: '.($c['draft_reply'] ?? 0);
        }
        if (! empty($plan['booking_url'])) {
            $lines[] = '• Booking link: '.$plan['booking_url'];
        }
        if (! empty($plan['next_action'])) {
            $lines[] = '• Next action: '.$plan['next_action'];
        }
        if (! empty($plan['stage'])) {
            $lines[] = '• Qualification: '.$plan['stage'];
        }
        if (! empty($plan['outcome'])) {
            $lines[] = '• Call outcome: '.str_replace('_', ' ', (string) $plan['outcome']);
        }
        if (! empty($plan['reason'])) {
            $lines[] = '• Why: '.$plan['reason'];
        }
        if (! empty($plan['adjustment'])) {
            $lines[] = '• Adjustment: '.str_replace('_', ' ', (string) $plan['adjustment']);
            if (! empty($plan['delta_days'])) {
                $lines[] = '• Delta: '.$plan['delta_days'].' day(s)';
            }
        }
        if (! empty($plan['evidence']) && is_array($plan['evidence'])) {
            $lines[] = '• Evidence: '.implode(', ', array_map(
                fn ($k, $v) => is_int($k) ? (string) $v : "{$k}: {$v}",
                array_keys($plan['evidence']),
                array_values($plan['evidence']),
            ));
        }
        if (! empty($plan['mode']) && ($plan['type'] ?? '') === 'enrichment') {
            $lines[] = '• Enrichment: '.$plan['mode'];
            if (isset($plan['email_eligible'])) {
                $lines[] = '• Email eligible: '.$plan['email_eligible'];
            }
            if (isset($plan['phone_eligible'])) {
                $lines[] = '• Phone eligible: '.$plan['phone_eligible'];
            }
        }

        if (! empty($plan['steps']) && is_array($plan['steps']) && ($plan['type'] ?? '') !== 'draft_reply') {
            $lines[] = '';
            $lines[] = 'Steps:';
            foreach (array_values($plan['steps']) as $i => $step) {
                $lines[] = ($i + 1).'. '.(is_string($step) ? $step : json_encode($step));
            }
        }

        if ($approvalId) {
            $lines[] = '';
            if ($surface === 'whatsapp') {
                $lines[] = "Reply:\nLAUNCH {$approvalId} — start when ready\nREVIEW {$approvalId} — see this again\nREJECT {$approvalId} — discard";
            } else {
                $lines[] = "Review & Launch ready (approval #{$approvalId}).";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Handle LAUNCH / APPROVE / REJECT / REVIEW / help without calling the LLM.
     *
     * @return array{handled:bool, reply?:string, rewrite?:string, approval?:AiActionApproval, decision?:string}|null
     */
    public function handleControlCommand(User $user, int $organizationId, string $text): ?array
    {
        $trimmed = trim($text);

        if (preg_match('/^\s*(LAUNCH|APPROVE|REJECT|REVIEW)\s*$/i', $trimmed, $bare)) {
            $verb = strtoupper($bare[1]);
            if ($verb === 'APPROVE') {
                $verb = 'LAUNCH';
            }

            $pending = $this->pendingApprovals($user, $organizationId);
            if ($pending->isEmpty()) {
                return [
                    'handled' => true,
                    'reply' => 'No plans waiting for review. Describe a goal and I\'ll stage one.',
                ];
            }

            // Button taps often arrive as bare "Launch" — default to the newest pending plan.
            $trimmed = $verb.' '.$pending->first()->id;
        }

        if (preg_match('/^\s*(LAUNCH|APPROVE|REJECT|REVIEW)\s+#(\d+)\s*$/i', $trimmed, $hash)) {
            $verb = strtoupper($hash[1]);
            if ($verb === 'APPROVE') {
                $verb = 'LAUNCH';
            }
            $trimmed = $verb.' '.$hash[2];
        }

        if (preg_match('/^\s*(LAUNCH|APPROVE|REJECT|REVIEW)[_\s#]+(\d+)\s*$/i', $trimmed, $m)) {
            $verb = strtoupper($m[1]);
            if ($verb === 'APPROVE') {
                $verb = 'LAUNCH';
            }
            $id = (int) $m[2];
            $approval = $this->approvals->findPendingForUser($user, $organizationId, $id);

            if (! $approval && $verb === 'REVIEW') {
                $approval = AiActionApproval::query()
                    ->where('id', $id)
                    ->where('user_id', $user->id)
                    ->where('organization_id', $organizationId)
                    ->first();
            }

            if (! $approval) {
                return [
                    'handled' => true,
                    'reply' => $this->approvalNotPendingReply($user, $organizationId, $id, $verb),
                ];
            }

            if ($verb === 'REVIEW') {
                return [
                    'handled' => true,
                    'reply' => $this->formatPlanCard(
                        $approval->payload ?? [],
                        $approval->status === 'pending' ? $approval->id : null,
                        'whatsapp'
                    ),
                    'approval' => $approval,
                    'decision' => 'review',
                ];
            }

            if ($approval->status !== 'pending') {
                return [
                    'handled' => true,
                    'reply' => $this->approvalNotPendingReply($user, $organizationId, $id, $verb),
                ];
            }

            if ($verb === 'LAUNCH') {
                $integrationBlock = $this->blockedLaunchMissingIntegrations($approval, $user);
                if ($integrationBlock !== null) {
                    return [
                        'handled' => true,
                        'reply' => $integrationBlock,
                        'decision' => 'blocked_launch',
                    ];
                }
            }

            if ($verb === 'LAUNCH' && $this->isOutreachPlanTool($approval)) {
                $audience = $this->audienceResolver->resolve($user, $approval->payload ?? [], strict: true);
                if ($audience === null) {
                    return [
                        'handled' => true,
                        'reply' => $this->missingAudienceLaunchReply($approval),
                        'decision' => 'blocked_launch',
                    ];
                }

                $approval->update([
                    'payload' => PlanLeadList::merge(
                        $approval->payload ?? [],
                        $audience['list_hash'],
                        $audience['list_src'],
                        $audience['list_name'],
                    ),
                ]);
                $approval = $approval->fresh();
            }

            if ($verb === 'REJECT') {
                $this->approvals->reject($approval, $user);

                return [
                    'handled' => true,
                    'reply' => "Rejected plan #{$id}. Tell me what you'd like instead.",
                    'approval' => $approval->fresh(),
                    'decision' => 'reject',
                ];
            }

            $this->approvals->approve($approval, $user);
            $fresh = $approval->fresh();

            return [
                'handled' => true,
                'reply' => $this->launchAcknowledged($fresh, $user),
                'approval' => $fresh->fresh(),
                'decision' => 'approve',
            ];
        }

        if (preg_match('/^\s*ACTIVATE\s+(\d+)\s*$/i', $trimmed, $m)) {
            $result = $this->campaignCommands->activate($user, $organizationId, (int) $m[1]);

            return [
                'handled' => true,
                'reply' => $result['message'],
                'decision' => 'activate',
            ];
        }

        if (preg_match('/^\s*PAUSE\s+(\d+)\s*$/i', $trimmed, $m)) {
            $result = $this->campaignCommands->pause($user, $organizationId, (int) $m[1]);

            return [
                'handled' => true,
                'reply' => $result['message'],
                'decision' => 'pause',
            ];
        }

        $lower = Str::lower($trimmed);

        if (in_array($lower, ['pause all', 'pause all campaigns', 'pause campaigns'], true)) {
            $result = $this->campaignCommands->pause($user, $organizationId, null);

            return [
                'handled' => true,
                'reply' => $result['message'],
                'decision' => 'pause_all',
            ];
        }

        if (in_array($lower, ['reply', 'draft reply'], true)) {
            return ['handled' => false, 'rewrite' => 'Who needs my attention? For the hottest lead, draft a reply with draft_reply.'];
        }

        if (in_array($lower, ['brief', 'sales brief', 'how am i doing'], true)) {
            return ['handled' => false, 'rewrite' => 'Give me a sales brief summary using get_sales_brief.'];
        }

        if (in_array($lower, ['weekly brief', 'weekly sales brief', 'sales manager brief'], true)) {
            return ['handled' => false, 'rewrite' => 'Give me the weekly sales manager brief using get_weekly_sales_brief.'];
        }

        if (in_array($lower, ['status', "how's it going?", 'how is it going?', 'campaign status'], true)) {
            return ['handled' => false, 'rewrite' => 'How are my campaigns performing? Summarize with get_campaign_stats.'];
        }

        if (in_array($lower, ['attention', 'who needs me?', 'who needs my attention?', 'inbox'], true)) {
            return ['handled' => false, 'rewrite' => 'Who needs my attention right now? Use get_attention_queue.'];
        }

        if (in_array($lower, ['help', 'menu', 'commands'], true)) {
            return [
                'handled' => true,
                'reply' => "I'm your SociFusion Command Center. Try:\n"
                    ."• Describe a sales goal\n"
                    ."• brief — sales snapshot\n"
                    ."• status — campaign performance\n"
                    ."• attention — hot leads\n"
                    ."• meeting brief — prep for next call\n"
                    ."• LAUNCH {id} / REJECT {id} / REVIEW {id}\n"
                    ."• ACTIVATE {campaign_id} — start outreach\n"
                    ."• PAUSE {campaign_id} or pause all",
            ];
        }

        if (in_array($lower, ['meeting brief', 'call brief', 'prep for call', 'next meeting'], true)) {
            return ['handled' => false, 'rewrite' => 'Give me a meeting brief for my next booked call using get_meeting_brief.'];
        }

        if (in_array($lower, ['execute', 'let ai execute', 'run recommendations', 'let alex execute'], true)) {
            return ['handled' => false, 'rewrite' => 'Build and stage a multi-step sales manager plan using let_ai_execute (pause struggling campaigns + follow up hot inbox threads).'];
        }

        if (preg_match('/^\s*optimize\s+(\d+)\s*$/i', $trimmed, $m)) {
            return ['handled' => false, 'rewrite' => 'Analyze and optimize outreach campaign '.(int) $m[1].' using optimize_campaign.'];
        }

        return null;
    }

    public function launchAcknowledged(AiActionApproval $approval, User $user): string
    {
        $tool = (string) $approval->tool;
        $type = (string) ($approval->payload['type'] ?? '');

        if ($tool === 'prepare_competitor_harvest' || $type === 'competitor_harvest') {
            return $this->launchCompetitorHarvest($approval, $user);
        }

        if ($tool === 'draft_reply' || $type === 'draft_reply') {
            return $this->launchDraftReply($approval, $user);
        }

        if ($tool === 'prepare_enrichment' || $type === 'enrichment') {
            return $this->launchEnrichment($approval, $user);
        }

        if ($tool === 'draft_personalized_message' || $type === 'personalized_message') {
            return $this->launchPersonalizedMessage($approval, $user);
        }

        if ($tool === 'adjust_follow_up' || $type === 'follow_up_adjustment') {
            return $this->launchFollowUpAdjust($approval, $user);
        }

        if ($tool === 'set_next_best_action' || $type === 'next_best_action') {
            return $this->launchNextBestAction($approval, $user);
        }

        if ($tool === 'optimize_campaign' || $type === 'campaign_optimization') {
            return $this->launchOptimizeCampaign($approval, $user);
        }

        if ($tool === 'build_icp' || $type === 'icp') {
            return $this->launchIcp($approval, $user);
        }

        if ($tool === 'qualify_lead' || $type === 'qualification') {
            return $this->launchQualifyLead($approval, $user);
        }

        if ($tool === 'post_call_crm_update' || $type === 'post_call_crm') {
            return $this->launchPostCallCrm($approval, $user);
        }

        if ($tool === 'book_meeting' || $type === 'book_meeting') {
            return $this->launchBookMeeting($approval, $user);
        }

        if ($tool === 'let_ai_execute' || $type === 'sales_manager_execute') {
            return $this->launchSalesManagerExecute($approval, $user);
        }

        return $this->launchCampaignPlan($approval, $user);
    }

    private function launchDraftReply(AiActionApproval $approval, User $user): string
    {
        try {
            $result = $this->replySend->sendFromApproval($approval->fresh() ?? $approval, $user);
        } catch (\Throwable $e) {
            report($e);

            return "Approved #{$approval->id}, but the reply couldn't send: ".$e->getMessage();
        }

        return implode("\n", [
            "Launched plan #{$approval->id}.",
            $result['message'],
            $result['inbox_url'],
        ]);
    }

    private function launchEnrichment(AiActionApproval $approval, User $user): string
    {
        try {
            $result = $this->enrichment->startFromApproval($approval->fresh() ?? $approval, $user);
        } catch (\Throwable $e) {
            report($e);

            return "Approved #{$approval->id}, but enrichment couldn't start: ".$e->getMessage();
        }

        $goal = $approval->payload['goal'] ?? 'Enrichment';

        return implode("\n", [
            "Launched plan #{$approval->id}: {$goal}",
            $result['message'],
            $result['skipped'] > 0
                ? "{$result['skipped']} contact(s) skipped due to daily limits — run again tomorrow."
                : 'Jobs are running in the background.',
        ]);
    }

    private function launchCompetitorHarvest(AiActionApproval $approval, User $user): string
    {
        try {
            $result = $this->competitorHarvest->startFromApproval($approval->fresh() ?? $approval, $user);
        } catch (\Throwable $e) {
            report($e);

            return "Approved #{$approval->id}, but harvest couldn't start: ".$e->getMessage();
        }

        $goal = $approval->payload['goal'] ?? 'Competitor harvest';

        return implode("\n", [
            "Launched plan #{$approval->id}: {$goal}",
            $result['message'],
            $result['url'],
            'When harvest completes, say "analyze competitor audience" then draft outreach.',
        ]);
    }

    private function launchPersonalizedMessage(AiActionApproval $approval, User $user): string
    {
        try {
            $result = $this->personalizedMessage->applyFromApproval($approval->fresh() ?? $approval, $user);
        } catch (\Throwable $e) {
            report($e);

            return "Approved #{$approval->id}, but the message couldn't be saved: ".$e->getMessage();
        }

        return implode("\n", [
            "Launched plan #{$approval->id}.",
            $result['message'],
            '"'.Str::limit($result['draft_text'], 300, '…').'"',
        ]);
    }

    private function launchFollowUpAdjust(AiActionApproval $approval, User $user): string
    {
        try {
            $result = $this->followUpAdjust->applyFromApproval($approval->fresh() ?? $approval, $user);
        } catch (\Throwable $e) {
            report($e);

            return "Approved #{$approval->id}, but sequence adjustment failed: ".$e->getMessage();
        }

        return implode("\n", [
            "Launched plan #{$approval->id}.",
            $result['message'],
            $result['outreach_url'],
        ]);
    }

    private function launchNextBestAction(AiActionApproval $approval, User $user): string
    {
        try {
            $result = $this->nextBestAction->applyFromApproval($approval->fresh() ?? $approval, $user);
        } catch (\Throwable $e) {
            report($e);

            return "Approved #{$approval->id}, but next action couldn't be saved: ".$e->getMessage();
        }

        $action = $approval->payload['next_action'] ?? '';

        return implode("\n", [
            "Launched plan #{$approval->id}.",
            $result['message'],
            'Action: '.$action,
        ]);
    }

    private function launchOptimizeCampaign(AiActionApproval $approval, User $user): string
    {
        try {
            $result = $this->optimizeCampaign->applyFromApproval($approval->fresh() ?? $approval, $user);
        } catch (\Throwable $e) {
            report($e);

            return "Approved #{$approval->id}, but optimization couldn't be saved: ".$e->getMessage();
        }

        $count = count($approval->payload['suggestions'] ?? []);

        return implode("\n", array_filter([
            "Launched plan #{$approval->id}.",
            $result['message'],
            $count > 0 ? "{$count} recommendation(s) saved — review in outreach builder." : null,
            ! empty($result['auto_applied']) ? 'Auto-applied: '.implode('; ', $result['auto_applied']) : null,
            $result['outreach_url'],
        ]));
    }

    private function launchIcp(AiActionApproval $approval, User $user): string
    {
        try {
            $result = $this->icpSave->applyFromApproval($approval->fresh() ?? $approval, $user);
        } catch (\Throwable $e) {
            report($e);

            return "Approved #{$approval->id}, but ICP couldn't be saved: ".$e->getMessage();
        }

        return implode("\n", [
            "Launched plan #{$approval->id}.",
            $result['message'],
            'Summary: '.($result['icp']['summary'] ?? ''),
        ]);
    }

    private function launchQualifyLead(AiActionApproval $approval, User $user): string
    {
        try {
            $result = $this->qualifyLead->applyFromApproval($approval->fresh() ?? $approval, $user);
        } catch (\Throwable $e) {
            report($e);

            return "Approved #{$approval->id}, but qualification couldn't be saved: ".$e->getMessage();
        }

        return implode("\n", [
            "Launched plan #{$approval->id}.",
            $result['message'],
        ]);
    }

    private function launchPostCallCrm(AiActionApproval $approval, User $user): string
    {
        try {
            $result = $this->postCallCrm->applyFromApproval($approval->fresh() ?? $approval, $user);
        } catch (\Throwable $e) {
            report($e);

            return "Approved #{$approval->id}, but CRM update couldn't be saved: ".$e->getMessage();
        }

        return implode("\n", [
            "Launched plan #{$approval->id}.",
            $result['message'],
        ]);
    }

    private function launchBookMeeting(AiActionApproval $approval, User $user): string
    {
        try {
            $result = $this->bookMeeting->applyFromApproval($approval->fresh() ?? $approval, $user);
        } catch (\Throwable $e) {
            report($e);

            return "Approved #{$approval->id}, but booking link couldn't be sent: ".$e->getMessage();
        }

        return implode("\n", [
            "Launched plan #{$approval->id}.",
            $result['message'],
            $result['inbox_url'],
        ]);
    }

    private function launchSalesManagerExecute(AiActionApproval $approval, User $user): string
    {
        try {
            $result = $this->executeSalesPlan->applyFromApproval($approval->fresh() ?? $approval, $user);
        } catch (\Throwable $e) {
            report($e);

            return "Approved #{$approval->id}, but sales plan couldn't run: ".$e->getMessage();
        }

        $lines = ["Launched plan #{$approval->id}.", $result['message']];
        foreach ($result['results'] as $row) {
            if (! ($row['ok'] ?? false)) {
                $lines[] = '⚠ '.($row['message'] ?? 'Step failed');
            }
        }

        return implode("\n", $lines);
    }

    private function launchCampaignPlan(AiActionApproval $approval, User $user): string
    {
        try {
            $created = $this->campaignDrafts->createFromApproval($approval->fresh() ?? $approval, $user);
        } catch (MissingProspectAudienceException $e) {
            return implode("\n", array_merge(
                ["Can't launch plan #{$approval->id} without a prospect list."],
                $e->nextSteps,
            ));
        } catch (\Throwable $e) {
            report($e);

            return "Approved #{$approval->id}, but I couldn't create the outreach draft: ".$e->getMessage();
        }

        $campaign = $created['campaign'];
        $lists = $created['attached_lists'];
        $goal = $approval->payload['goal'] ?? $campaign->name;
        $audienceName = data_get($approval->payload, 'list_name')
            ?? data_get($approval->payload, 'audience_note')
            ?? 'attached list';

        $lines = [
            "Launched plan #{$approval->id}: {$goal}",
            "Draft outreach campaign #{$campaign->id} created ({$created['template_type']}).",
            "Audience: {$audienceName}.",
        ];

        if ($lists > 0) {
            $activate = $this->campaignCommands->activate(
                $user,
                (int) $approval->organization_id,
                $campaign->id,
            );
            $lines[] = $activate['ok']
                ? $activate['message']
                : "List attached. {$activate['message']}";
        }

        $missing = $this->guard->missingChannels(
            $user->id,
            is_array($campaign->node_model) ? $campaign->node_model : [],
        );
        if ($missing !== []) {
            $labels = array_map(
                fn (string $channel) => OutreachChannelRegistry::channelLabel($channel),
                $missing,
            );
            $lines[] = 'Before outreach can send: connect '.implode(', ', $labels).' on Integrations ('.url('/integrations').').';
        }

        $lines[] = $created['url'];

        return implode("\n", $lines);
    }

    private function isOutreachPlanTool(AiActionApproval $approval): bool
    {
        if (in_array($approval->tool, ['propose_strategy', 'draft_campaign_plan'], true)) {
            return true;
        }

        $type = (string) ($approval->payload['type'] ?? '');

        return in_array($type, ['strategy', 'campaign'], true);
    }

    private function missingAudienceLaunchReply(AiActionApproval $approval): string
    {
        $steps = $this->audienceResolver->nextSteps($approval->payload ?? []);
        $goal = $approval->payload['goal'] ?? 'this goal';

        return implode("\n", array_merge(
            [
                "I won't create a campaign for plan #{$approval->id} until we have a prospect list for {$goal}.",
                '',
                'Next:',
            ],
            array_map(fn (string $step) => '• '.$step, $steps),
        ));
    }

    private function approvalNotPendingReply(User $user, int $organizationId, int $id, string $verb): string
    {
        $existing = AiActionApproval::query()
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->first();

        if (! $existing) {
            return implode("\n", [
                "I couldn't find plan #{$id}.",
                '',
                'Check the plan number, or describe a new goal and I\'ll stage a fresh plan.',
            ]);
        }

        if ($existing->status === 'pending') {
            return "Plan #{$id} is still waiting — send *LAUNCH {$id}* when you're ready.";
        }

        if (in_array($existing->status, ['approved', 'executed'], true)) {
            return implode("\n", array_filter([
                "Plan #{$id} was already launched — there's nothing left to {$verb}.",
                '',
                $this->nextStepAfterLaunch($existing, $user, $organizationId),
            ]));
        }

        if ($existing->status === 'rejected') {
            return implode("\n", [
                "Plan #{$id} was rejected.",
                '',
                'Tell me what you\'d like instead and I\'ll stage a new plan.',
            ]);
        }

        return implode("\n", [
            "Plan #{$id} is {$existing->status}.",
            '',
            'Say *help* or describe a new goal to continue.',
        ]);
    }

    private function nextStepAfterLaunch(AiActionApproval $approval, User $user, int $organizationId): string
    {
        $type = (string) ($approval->payload['type'] ?? '');
        $tool = (string) $approval->tool;

        if ($tool === 'build_icp' || $type === 'icp') {
            return 'Next: say *find prospects* to build a list from your ICP. Before outreach sends, connect **LinkedIn + Email** in SociFusion → Integrations.';
        }

        if ($type === 'strategy' || $tool === 'propose_strategy') {
            return 'Next: say *find prospects* or *draft campaign*. Connect **LinkedIn + Email** on Integrations before messages can send.';
        }

        $pending = $this->pendingApprovals($user, $organizationId)->first();
        if ($pending) {
            return "You still have plan #{$pending->id} waiting — send *REVIEW {$pending->id}* or *LAUNCH {$pending->id}*.";
        }

        return 'Describe your next goal, or say *help* for commands.';
    }

    private function blockedLaunchMissingIntegrations(AiActionApproval $approval, User $user): ?string
    {
        if (! $this->launchRequiresOutreachIntegrations($approval, $user)) {
            return null;
        }

        $policy = app(AiChannelPolicyService::class);
        $mentioned = $policy->mentionedInPlan($approval->payload ?? []);
        if ($mentioned === []) {
            $mentioned = $policy->primaryKeys();
        }

        if ($approval->tool === 'prepare_competitor_harvest' || ($approval->payload['type'] ?? '') === 'competitor_harvest') {
            $mentioned = array_values(array_unique(array_merge(['linkedin'], $mentioned)));
        }

        $missing = [];
        foreach ($mentioned as $channelKey) {
            if (! OutreachChannelRegistry::isEnabled($channelKey)) {
                continue;
            }
            if (! $this->guard->isChannelConnected($user->id, $channelKey)) {
                $missing[] = OutreachChannelRegistry::channelLabel($channelKey);
            }
        }

        if ($missing === []) {
            return null;
        }

        return implode("\n", [
            "I can't launch plan #{$approval->id} yet — your account still needs: **".implode(', ', $missing).'**.',
            '',
            'This is a setup step on your side (not a SociFusion outage):',
            '1. Open SociFusion → **Integrations**',
            '2. Connect '.implode(' and ', $missing),
            '3. Send *LAUNCH '.$approval->id.'* again',
            '',
            url('/integrations'),
        ]);
    }

    private function launchRequiresOutreachIntegrations(AiActionApproval $approval, User $user): bool
    {
        if (in_array($approval->tool, [
            'draft_reply',
            'prepare_competitor_harvest',
            'draft_campaign_plan',
        ], true)) {
            return true;
        }

        $type = (string) ($approval->payload['type'] ?? '');

        if (in_array($type, ['draft_reply', 'competitor_harvest', 'campaign'], true)) {
            return true;
        }

        if ($approval->tool === 'propose_strategy' || $type === 'strategy') {
            return $this->audienceResolver->resolve($user, $approval->payload ?? [], strict: true) !== null;
        }

        return false;
    }

    /**
     * @param  Collection<int, AiActionApproval>  $pending
     * @return list<array<string, mixed>>
     */
    public function serializeApprovals(Collection $pending): array
    {
        return $pending->map(fn (AiActionApproval $a) => [
            'id' => $a->id,
            'tool' => $a->tool,
            'permission' => $a->permission,
            'status' => $a->status,
            'payload' => $a->payload,
            'card_text' => $this->formatPlanCard($a->payload ?? [], $a->id, 'web'),
            'created_at' => $a->created_at?->toIso8601String(),
        ])->values()->all();
    }
}

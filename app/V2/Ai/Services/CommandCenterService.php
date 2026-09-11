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
        private readonly ContentPostCommandCenterService $contentPosts,
        private readonly CallManagerCommandCenterService $callManager,
        private readonly OutreachChannelGuard $guard,
        private readonly ProspectAudienceResolverService $audienceResolver,
        private readonly LinkedInAudienceBuilderService $linkedInAudience,
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
     * Archive the open Command Center thread and open a blank one.
     * Messages are kept on the archived conversation (not deleted).
     * Pending Review & Launch approvals are untouched.
     *
     * @return array{
     *     conversation: AiConversation,
     *     archived_conversation_id: int|null,
     *     welcome: array<string, mixed>
     * }
     */
    public function startFreshConversation(User $user, int $organizationId): array
    {
        $open = AiConversation::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->where('status', 'open')
            ->orderByDesc('id')
            ->get();

        $channelIdentityId = $open->first(fn (AiConversation $c) => $c->channel_identity_id)?->channel_identity_id;
        $archivedId = $open->first()?->id;

        foreach ($open as $conversation) {
            $conversation->update([
                'status' => 'archived',
                'meta' => array_merge(
                    is_array($conversation->meta) ? $conversation->meta : [],
                    [
                        'archived_at' => now()->toIso8601String(),
                        'archived_reason' => 'clear_chat',
                    ],
                ),
            ]);
        }

        $fresh = AiConversation::query()->create([
            'organization_id' => $organizationId,
            'user_id' => $user->id,
            'channel' => 'command_center',
            'channel_identity_id' => $channelIdentityId,
            'status' => 'open',
            'title' => 'Command Center',
            'meta' => [
                'started_from_clear' => true,
                'previous_conversation_id' => $archivedId,
            ],
        ]);

        $settings = app(AiEmployeeSettingsService::class)->for($user, $organizationId);
        $name = $settings->employee_name ?: 'Soci';
        $welcomeContent = "Hi — I'm {$name}, your SociFusion Command Center.\n"
            ."Fresh thread started. Pending Launch items are still in Review & Launch.\n"
            .'Tell me what you want to accomplish (same WhatsApp link still works).';

        $welcome = AiMessage::query()->create([
            'conversation_id' => $fresh->id,
            'role' => 'assistant',
            'content' => $welcomeContent,
            'meta' => ['channel' => 'web', 'system' => 'clear_chat_welcome'],
        ]);

        return [
            'conversation' => $fresh,
            'archived_conversation_id' => $archivedId ? (int) $archivedId : null,
            'welcome' => [
                'id' => $welcome->id,
                'role' => 'assistant',
                'content' => $welcome->content,
                'channel' => 'web',
                'created_at' => $welcome->created_at?->toIso8601String(),
            ],
        ];
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
     * Newer messages after a cursor (used by web chat polling).
     *
     * @return Collection<int, AiMessage>
     */
    public function messagesAfter(AiConversation $conversation, int $afterId, int $limit = 50): Collection
    {
        $limit = max(1, min(100, $limit));

        return AiMessage::query()
            ->where('conversation_id', $conversation->id)
            ->whereIn('role', ['user', 'assistant'])
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->get();
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
            'progress' => is_array($m->meta) ? (bool) ($m->meta['progress'] ?? false) : false,
            'created_at' => $m->created_at?->toIso8601String(),
        ])->values()->all();
    }

    /**
     * @return Collection<int, AiActionApproval>
     */
    public function pendingApprovals(User $user, int $organizationId): Collection
    {
        $pending = AiActionApproval::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        // Widget + WhatsApp show one CTA — drop older stacked pendings.
        if ($pending->count() > 1) {
            $keepId = (int) $pending->first()->id;
            AiActionApproval::query()
                ->where('user_id', $user->id)
                ->where('organization_id', $organizationId)
                ->where('status', 'pending')
                ->where('id', '!=', $keepId)
                ->update([
                    'status' => 'rejected',
                    'decided_at' => now(),
                    'decided_by' => $user->id,
                ]);

            return $pending->take(1)->values();
        }

        return $pending;
    }

    public function formatPlanCard(array $plan, ?int $approvalId = null, string $surface = 'web', ?string $tool = null): string
    {
        $lines = ['Got it. Here\'s the plan:'];

        if (! empty($plan['funnel']) && is_array($plan['funnel']) && in_array($plan['type'] ?? '', ['strategy', 'campaign'], true)) {
            $lines[] = '';
            $lines[] = 'Funnel:';
            foreach ($plan['funnel'] as $step) {
                if (! is_array($step)) {
                    continue;
                }
                $mark = match ((string) ($step['status'] ?? '')) {
                    'ready' => '✓',
                    'blocked' => '!',
                    default => '·',
                };
                $lines[] = "{$mark} ".($step['label'] ?? 'Step').': '.($step['detail'] ?? '');
            }
            $lines[] = '';
        }

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
        if (array_key_exists('pause_on_reply', $plan) || in_array(($plan['type'] ?? ''), ['campaign', 'strategy'], true)) {
            $pause = array_key_exists('pause_on_reply', $plan) ? (bool) $plan['pause_on_reply'] : true;
            $lines[] = '• Pause on reply: '.($pause ? 'Yes — Soci/you reply in inbox' : 'No');
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
            if (! empty($c['move_to_nurture'])) {
                $lines[] = '• Move to nurture: '.$c['move_to_nurture'];
            }
            if (! empty($c['scale_campaign'])) {
                $lines[] = '• Scale volume: '.$c['scale_campaign'];
            }
            if (! empty($c['activate_campaign'])) {
                $lines[] = '• Activate winners: '.$c['activate_campaign'];
            }
            if (! empty($c['shift_channel_mix'])) {
                $lines[] = '• Channel mix shifts: '.$c['shift_channel_mix'];
            }
        }
        if (($plan['type'] ?? '') === 'icp') {
            $icp = is_array($plan['icp'] ?? null) ? $plan['icp'] : [];
            if (! empty($icp['website'] ?? $plan['website'] ?? null)) {
                $lines[] = '• Website: '.($icp['website'] ?? $plan['website']);
            }
            if (! empty($icp['customers'] ?? $plan['customers'] ?? null)) {
                $customers = $icp['customers'] ?? $plan['customers'];
                $lines[] = '• Customers: '.(is_array($customers) ? implode(', ', $customers) : $customers);
            }
            if (! empty($icp['competitors']) && is_array($icp['competitors'])) {
                $lines[] = '• Competitors: '.implode(', ', $icp['competitors']);
            }
            if (! empty($icp['decision_maker'])) {
                $lines[] = '• Decision maker: '.$icp['decision_maker'];
            }
            if (! empty($icp['likely_pain'])) {
                $lines[] = '• Pain: '.$icp['likely_pain'];
            }
            $lines[] = '• Next after Launch: say "find prospects" to search LinkedIn from this ICP';
        }
        if (! empty($plan['booking_url'])) {
            $lines[] = '• Booking link: '.$plan['booking_url'];
        }
        if (($plan['type'] ?? '') === 'linkedin_post') {
            $lines[] = '• Channel: LinkedIn (Content module)';
            if (! empty($plan['post_preview'])) {
                $lines[] = '';
                $lines[] = 'Preview:';
                $lines[] = '"'.Str::limit((string) $plan['post_preview'], 400, '…').'"';
            }
            if (! empty($plan['content_url'])) {
                $lines[] = '• Edit in Content: '.$plan['content_url'];
            }
            if (($plan['linkedin_connected'] ?? true) === false) {
                $lines[] = '• Connect LinkedIn in Integrations before Launch can publish';
            }
            if (! empty($plan['scheduled_label'])) {
                $lines[] = '• Schedule: '.$plan['scheduled_label'];
            }
            if (! empty($plan['has_image'])) {
                $lines[] = '• Image: AI-generated (saved in Content)';
            }
            if (! empty($plan['image_error'])) {
                $lines[] = '• Image note: '.$plan['image_error'];
            }
            if (($plan['status'] ?? '') === 'scheduled') {
                $lines[] = '• Status: scheduled automatically';
            }
        }
        if (($plan['type'] ?? '') === 'content_reschedule') {
            $lines[] = '• Module: Content';
            if (! empty($plan['from_day'])) {
                $lines[] = '• From: posts scheduled on '.$plan['from_day'];
            }
            if (! empty($plan['to_schedule_at'])) {
                $lines[] = '• To: '.$plan['to_schedule_at'];
            }
            if (! empty($plan['post_ids']) && is_array($plan['post_ids'])) {
                $lines[] = '• Posts: '.count($plan['post_ids']);
            }
            if (! empty($plan['content_url'])) {
                $lines[] = '• View in Content: '.$plan['content_url'];
            }
        }
        if (($plan['type'] ?? '') === 'call_manager_launch') {
            $lines[] = '• Module: Call Manager';
            if (! empty($plan['prospect_count'])) {
                $lines[] = '• Prospects to queue: '.$plan['prospect_count'];
            }
            if (! empty($plan['audience'])) {
                $lines[] = '• Audience: '.$plan['audience'];
            }
            if (! empty($plan['opening_message'])) {
                $lines[] = '• Opening: "'.Str::limit((string) $plan['opening_message'], 200, '…').'"';
            }
            if (! empty($plan['calls_url'])) {
                $lines[] = '• View in Call Manager: '.$plan['calls_url'];
            }
            if (($plan['linkedin_connected'] ?? true) === false) {
                $lines[] = '• Connect LinkedIn in Integrations before Launch can start chats';
            }
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
        if (($plan['type'] ?? '') === 'campaign_inbox_ai') {
            $lines[] = '• Campaign: '.($plan['campaign_name'] ?? '#'.($plan['campaign_id'] ?? ''));
            $lines[] = '• Channel: '.($plan['channel_label'] ?? $plan['channel'] ?? 'inbox');
            $lines[] = '• Auto-reply: '.(! empty($plan['auto_reply_enabled']) ? 'On' : 'Off');
            $lines[] = '• Pause on reply: '.(! empty($plan['pause_on_reply']) ? 'Yes' : 'No');
            if (! empty($plan['ai_context'])) {
                $lines[] = '• AI context: "'.Str::limit((string) $plan['ai_context'], 200, '…').'"';
            }
        }
        if (($plan['type'] ?? '') === 'csv_import') {
            $lines[] = '• List name: '.($plan['list_name'] ?? 'Import');
            if (isset($plan['preview_rows'])) {
                $lines[] = '• Rows to import: '.$plan['preview_rows'];
            }
            $lines[] = '• Launch creates list_hash for draft_campaign_plan / propose_strategy';
        }
        if (in_array(($plan['type'] ?? ''), ['campaign_delete', 'resource_delete', 'bulk_delete'], true)) {
            $lines[] = '• Delete: '.($plan['resource_name'] ?? $plan['campaign_name'] ?? '#'.($plan['resource_id'] ?? $plan['campaign_id'] ?? ''));
            if (! empty($plan['item_count'])) {
                $lines[] = '• Items: '.$plan['item_count'].' (one Confirm Delete removes all)';
            }
            if (! empty($plan['kind'])) {
                $lines[] = '• Kind: '.$plan['kind'];
            }
            if (! empty($plan['detail'])) {
                $lines[] = '• Detail: '.$plan['detail'];
            }
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
            $labels = \App\V2\Ai\Support\ApprovalActionLabels::for(
                $tool ?? (is_string($plan['tool'] ?? null) ? $plan['tool'] : null),
                $plan,
            );
            if ($surface === 'whatsapp') {
                $actionHints = [
                    "• *{$labels['approve']}* — go ahead",
                ];
                if ($labels['show_preview']) {
                    $actionHints[] = "• *{$labels['preview']}* — see this again";
                }
                $actionHints[] = "• *{$labels['reject']}* — discard";
                $lines[] = "Tap a button below (or reply):\n".implode("\n", $actionHints);
            } else {
                $lines[] = "{$labels['summary']} — use Review & Launch when you're ready.";
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

        if (app(UserTurnIntentService::class)->isInformational($trimmed)) {
            return [
                'handled' => true,
                'reply' => app(SalesBriefService::class)->todaySummary($user, $organizationId),
                'decision' => 'status_brief',
            ];
        }

        // Confirm Delete → all pending destructive plans (or one bulk_delete plan).
        if (preg_match('/^\s*confirm\s+delete(\s+all)?\s*$/i', $trimmed)) {
            return $this->confirmPendingDeletes($user, $organizationId);
        }

        if (preg_match('/^\s*(LAUNCH|APPROVE|REJECT|REVIEW|PUBLISH|SEND|SAVE|DISCARD|PREVIEW|IMPORT|ENRICH)\s*$/i', $trimmed, $bare)) {
            $verb = match (strtoupper($bare[1])) {
                'APPROVE', 'PUBLISH', 'SEND', 'SAVE', 'IMPORT', 'ENRICH' => 'LAUNCH',
                'DISCARD' => 'REJECT',
                'PREVIEW' => 'REVIEW',
                default => strtoupper($bare[1]),
            };

            $pending = $this->pendingApprovals($user, $organizationId);
            if ($pending->isEmpty()) {
                return [
                    'handled' => true,
                    'reply' => 'No plans waiting for review. Describe a goal and I\'ll stage one.',
                ];
            }

            // Button taps often arrive as bare action words — default to the newest pending plan.
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

            // Retry LAUNCH when approve succeeded but draft creation failed (still "approved", no campaign).
            if (! $approval && $verb === 'LAUNCH') {
                $candidate = AiActionApproval::query()
                    ->where('id', $id)
                    ->where('user_id', $user->id)
                    ->where('organization_id', $organizationId)
                    ->where('status', 'approved')
                    ->first();
                if ($candidate && empty(data_get($candidate->result, 'outreach_campaign_id'))) {
                    $approval = $candidate;
                }
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
                        'whatsapp',
                        $approval->tool,
                    ),
                    'approval' => $approval,
                    'decision' => 'review',
                ];
            }

            if (! in_array($approval->status, ['pending', 'approved'], true)
                || ($approval->status === 'approved' && ! empty(data_get($approval->result, 'outreach_campaign_id')))
            ) {
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
                    $attached = $this->attachAutoAudience($user, $organizationId, $approval);
                    $audience = $attached ?? $this->audienceResolver->resolve($user, $approval->payload ?? [], strict: true);
                }

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

            if ($approval->status === 'pending') {
                $this->approvals->approve($approval, $user);
            }
            $fresh = $approval->fresh() ?? $approval;

            return [
                'handled' => true,
                'reply' => $this->launchAcknowledged($fresh, $user),
                'approval' => $fresh->fresh() ?? $fresh,
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

        if (in_array($lower, ['attention', 'who needs me', 'who needs me?', 'who needs my attention', 'who needs my attention?', 'inbox'], true)) {
            return ['handled' => false, 'rewrite' => 'Who needs my attention right now? Use get_attention_queue.'];
        }

        if (preg_match('/\b(nurture due|due for nurture|nurture follow[- ]?up)\b/i', $lower)) {
            return ['handled' => false, 'rewrite' => "Who's due for nurture follow-up? Use get_nurture_due_queue."];
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
                    ."• go ahead / yes — launch the newest pending plan\n"
                    ."• ACTIVATE {campaign_id} — start outreach\n"
                    ."• PAUSE {campaign_id} or pause all",
            ];
        }

        if (in_array($lower, ['meeting brief', 'call brief', 'prep for call', 'next meeting'], true)) {
            return ['handled' => false, 'rewrite' => 'Give me a meeting brief for my next booked call using get_meeting_brief.'];
        }

        if (in_array($lower, ['execute', 'let ai execute', 'run recommendations', 'let Soci execute'], true)) {
            return ['handled' => false, 'rewrite' => 'Build and stage a multi-step sales manager plan using let_ai_execute (pause struggling campaigns + follow up hot inbox threads).'];
        }

        if (preg_match('/^\s*optimize\s+(\d+)\s*$/i', $trimmed, $m)) {
            return ['handled' => false, 'rewrite' => 'Analyze and optimize outreach campaign '.(int) $m[1].' using optimize_campaign.'];
        }

        if ($this->isFuzzyLaunchConfirmation($trimmed)) {
            $pending = $this->pendingApprovals($user, $organizationId);
            if ($pending->isNotEmpty()) {
                $newest = $pending->first();

                // Fuzzy "yes/go ahead" on a delete should confirm all pending deletes.
                if ($this->isDeleteApproval($newest)) {
                    return $this->confirmPendingDeletes($user, $organizationId);
                }

                if ($this->isOutreachPlanTool($newest)
                    && $this->audienceResolver->resolve($user, $newest->payload ?? [], strict: true) === null) {
                    $attached = $this->attachAutoAudience($user, $organizationId, $newest);
                    if ($attached !== null) {
                        return $this->handleControlCommand($user, $organizationId, 'LAUNCH '.$newest->id);
                    }

                    $goal = (string) ($newest->payload['goal'] ?? 'their goal');

                    return [
                        'handled' => false,
                        'rewrite' => "The user approved plan #{$newest->id} ({$goal}) but no prospect list is attached yet. "
                            .'Run discover_prospects for this goal (Soci auto-searches LinkedIn when connected), '
                            ."attach list_hash + list_src to the plan, then tell them to send LAUNCH {$newest->id}.",
                    ];
                }

                return $this->handleControlCommand($user, $organizationId, 'LAUNCH '.$newest->id);
            }

            return [
                'handled' => true,
                'reply' => 'No plans waiting for review. Describe a goal and I\'ll stage one.',
            ];
        }

        return null;
    }

    /**
     * Confirm Delete / yes on destructive plans: prefer one bulk_delete, else run every pending delete.
     *
     * @return array{handled:bool, reply:string, decision?:string, approval?:AiActionApproval}
     */
    public function confirmPendingDeletes(User $user, int $organizationId): array
    {
        $pendingDeletes = $this->pendingApprovals($user, $organizationId)
            ->filter(fn (AiActionApproval $a) => $this->isDeleteApproval($a))
            ->values();

        if ($pendingDeletes->isEmpty()) {
            return [
                'handled' => true,
                'reply' => 'No delete plans waiting. Tell me what to delete and I\'ll stage one Confirm Delete.',
                'decision' => 'none',
            ];
        }

        $bulk = $pendingDeletes->first(
            fn (AiActionApproval $a) => ($a->payload['type'] ?? '') === 'bulk_delete'
        );

        if ($bulk) {
            // Reject other single delete siblings so Confirm Delete is one clean action.
            foreach ($pendingDeletes as $sibling) {
                if ((int) $sibling->id === (int) $bulk->id) {
                    continue;
                }
                $this->approvals->reject($sibling, $user);
            }

            return $this->handleControlCommand($user, $organizationId, 'LAUNCH '.$bulk->id)
                ?? [
                    'handled' => true,
                    'reply' => 'Could not confirm bulk delete #'.$bulk->id.'.',
                    'decision' => 'error',
                ];
        }

        if ($pendingDeletes->count() === 1) {
            return $this->handleControlCommand($user, $organizationId, 'LAUNCH '.$pendingDeletes->first()->id)
                ?? [
                    'handled' => true,
                    'reply' => 'Could not confirm delete.',
                    'decision' => 'error',
                ];
        }

        $lines = ['Confirmed delete for '.$pendingDeletes->count().' staged plan(s):'];
        $lastApproval = null;
        foreach ($pendingDeletes->sortBy('id') as $approval) {
            $launch = $this->handleControlCommand($user, $organizationId, 'LAUNCH '.$approval->id);
            $lines[] = (string) ($launch['reply'] ?? ('Plan #'.$approval->id));
            $lastApproval = $launch['approval'] ?? $approval;
        }

        return [
            'handled' => true,
            'reply' => implode("\n", $lines),
            'decision' => 'approve',
            'approval' => $lastApproval instanceof AiActionApproval ? $lastApproval : null,
        ];
    }

    public function isDeleteApproval(AiActionApproval $approval): bool
    {
        return $approval->tool === 'delete_campaign'
            || $approval->tool === 'delete_resource'
            || in_array(($approval->payload['type'] ?? ''), ['campaign_delete', 'resource_delete', 'bulk_delete'], true)
            || ($approval->payload['destructive'] ?? false) === true;
    }

    private function isFuzzyLaunchConfirmation(string $text): bool
    {
        $lower = Str::lower(trim($text));

        if (preg_match('/\b(go ahead|proceed|let\'?s go|start it|run it|do it now|sounds good|yes please|make it happen|ship it|confirm delete)\b/', $lower)) {
            return true;
        }

        return in_array($lower, ['yes', 'yep', 'yeah', 'ok', 'okay', 'sure', 'do it'], true);
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

        if ($tool === 'prepare_linkedin_post' || $type === 'linkedin_post') {
            return $this->launchLinkedInPost($approval, $user);
        }

        if ($tool === 'prepare_call_manager_launch' || $type === 'call_manager_launch') {
            return $this->launchCallManager($approval, $user);
        }

        if ($tool === 'reschedule_content_posts' || $type === 'content_reschedule') {
            return $this->launchContentReschedule($approval, $user);
        }

        if ($tool === 'configure_campaign_inbox_ai' || $type === 'campaign_inbox_ai') {
            return $this->launchCampaignInboxAi($approval, $user);
        }

        if ($tool === 'delete_campaign'
            || $tool === 'delete_resource'
            || in_array($type, ['campaign_delete', 'resource_delete', 'bulk_delete'], true)
        ) {
            return $this->launchCampaignDelete($approval, $user);
        }

        if ($tool === 'import_leads_csv' || $tool === 'save_contacts' || $type === 'csv_import') {
            return $this->launchCsvImport($approval, $user);
        }

        return $this->launchCampaignPlan($approval, $user);
    }

    private function launchCampaignInboxAi(AiActionApproval $approval, User $user): string
    {
        try {
            $result = app(CampaignInboxAiFromPlanService::class)->applyFromApproval($approval->fresh() ?? $approval, $user);
        } catch (\Throwable $e) {
            report($e);

            return "Approved #{$approval->id}, but inbox AI settings couldn't save: ".$e->getMessage();
        }

        return implode("\n", [
            "Launched plan #{$approval->id}.",
            $result['message'],
            $result['campaign_url'],
        ]);
    }

    private function launchCampaignDelete(AiActionApproval $approval, User $user): string
    {
        try {
            $result = app(DeleteCampaignCommandCenterService::class)->applyFromApproval(
                $approval->fresh() ?? $approval,
                $user,
            );
        } catch (\Throwable $e) {
            report($e);

            return "Approved #{$approval->id}, but delete failed: ".$e->getMessage();
        }

        $count = is_array($result['deleted'] ?? null) ? count($result['deleted']) : 1;

        return implode("\n", array_filter([
            $count > 1
                ? "Confirmed delete plan #{$approval->id} ({$count} items)."
                : "Confirmed delete plan #{$approval->id}.",
            $result['message'],
        ]));
    }

    private function launchCsvImport(AiActionApproval $approval, User $user): string
    {
        try {
            $result = app(ImportLeadsCsvFromPlanService::class)->importFromApproval($approval->fresh() ?? $approval, $user);
        } catch (\Throwable $e) {
            report($e);

            return "Approved #{$approval->id}, but CSV import failed: ".$e->getMessage();
        }

        $listHash = (string) ($result['list']['list_hash'] ?? '');

        return implode("\n", [
            "Launched plan #{$approval->id}.",
            $result['message'],
            $result['skipped'] > 0 ? "{$result['skipped']} row(s) skipped." : '',
            $listHash !== '' ? "List attached: {$listHash} (csv). Say \"draft campaign\" to use it." : '',
        ]);
    }

    public function launchIntegrationBlockMessage(AiActionApproval $approval, User $user): ?string
    {
        return $this->blockedLaunchMissingIntegrations($approval, $user);
    }

    private function launchLinkedInPost(AiActionApproval $approval, User $user): string
    {
        try {
            $result = $this->contentPosts->publishFromApproval($approval->fresh() ?? $approval, $user);
        } catch (\Throwable $e) {
            report($e);

            return "Approved #{$approval->id}, but LinkedIn publish failed: ".$e->getMessage();
        }

        return implode("\n", [
            "Launched plan #{$approval->id}.",
            $result['message'],
            'View in Content: '.$result['content_url'],
        ]);
    }

    private function launchCallManager(AiActionApproval $approval, User $user): string
    {
        try {
            $result = $this->callManager->launchFromApproval($approval->fresh() ?? $approval, $user);
        } catch (\Throwable $e) {
            report($e);

            return "Approved #{$approval->id}, but Call Manager launch failed: ".$e->getMessage();
        }

        return implode("\n", [
            "Launched plan #{$approval->id}.",
            $result['message'],
            'View in Call Manager: '.$result['calls_url'],
        ]);
    }

    private function launchContentReschedule(AiActionApproval $approval, User $user): string
    {
        try {
            $result = $this->contentPosts->executeRescheduleFromApproval($approval->fresh() ?? $approval);
        } catch (\Throwable $e) {
            report($e);

            return "Approved #{$approval->id}, but reschedule failed: ".$e->getMessage();
        }

        return implode("\n", [
            "Launched plan #{$approval->id}.",
            $result['message'],
            'View in Content: '.url('/content'),
        ]);
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
            'Say *find prospects* to search LinkedIn from this ICP.',
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
            $conversation = $approval->conversation_id
                ? AiConversation::query()->find($approval->conversation_id)
                : null;
            try {
                app(AiErrorLogService::class)->capture(
                    $e,
                    'launch:'.Str::limit((string) $approval->tool, 48, ''),
                    $user,
                    (int) $approval->organization_id,
                    $conversation,
                    $conversation?->channel,
                    null,
                    [
                        'approval_id' => $approval->id,
                        'tool' => $approval->tool,
                        'goal' => $approval->payload['goal'] ?? null,
                        'list_hash' => $approval->payload['list_hash'] ?? null,
                        'list_name' => $approval->payload['list_name'] ?? null,
                    ],
                );
            } catch (\Throwable $logError) {
                report($logError);
            }

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

    public function isOutreachPlanApproval(AiActionApproval $approval): bool
    {
        return $this->isOutreachPlanTool($approval);
    }

    public function isLinkedInPostApproval(AiActionApproval $approval): bool
    {
        if ($approval->tool === 'prepare_linkedin_post') {
            return true;
        }

        return ($approval->payload['type'] ?? '') === 'linkedin_post';
    }

    public function isCallManagerLaunchApproval(AiActionApproval $approval): bool
    {
        if ($approval->tool === 'prepare_call_manager_launch') {
            return true;
        }

        return ($approval->payload['type'] ?? '') === 'call_manager_launch';
    }

    public function hasFutureScheduledLinkedInPost(AiActionApproval $approval): bool
    {
        if (! $this->isLinkedInPostApproval($approval)) {
            return false;
        }

        $scheduled = $this->contentPosts->resolveScheduledFor($approval->payload ?? []);

        return $scheduled !== null && $scheduled->isFuture();
    }

    public function isAutoLaunchApproval(AiActionApproval $approval): bool
    {
        // Never auto-confirm destructive plans (deletes), even on Autopilot/Autonomous.
        if ($approval->tool === 'delete_campaign'
            || $approval->tool === 'delete_resource'
            || in_array(($approval->payload['type'] ?? ''), ['campaign_delete', 'resource_delete', 'bulk_delete'], true)
            || ($approval->payload['destructive'] ?? false) === true
            || ($approval->payload['requires_explicit_approval'] ?? false) === true
        ) {
            return false;
        }

        return $this->isOutreachPlanApproval($approval)
            || $this->isLinkedInPostApproval($approval)
            || $this->isCallManagerLaunchApproval($approval)
            || $this->isContentRescheduleApproval($approval);
    }

    public function isContentRescheduleApproval(AiActionApproval $approval): bool
    {
        if ($approval->tool === 'reschedule_content_posts') {
            return true;
        }

        return ($approval->payload['type'] ?? '') === 'content_reschedule';
    }

    /**
     * Auto-search LinkedIn and attach audience to a pending outreach plan.
     *
     * @return array{list_hash:string,list_src:string,list_name:string,total_leads:int}|null
     */
    public function attachAutoAudience(User $user, int $organizationId, AiActionApproval $approval): ?array
    {
        $built = $this->linkedInAudience->tryBuildFromPlan($user, $organizationId, $approval->payload ?? []);
        if ($built === null) {
            return null;
        }

        $approval->update([
            'payload' => PlanLeadList::merge(
                $approval->payload ?? [],
                $built['list_hash'],
                $built['list_src'],
                $built['list_name'],
            ),
        ]);

        return $built;
    }

    private function missingAudienceLaunchReply(AiActionApproval $approval): string
    {
        $steps = $this->audienceResolver->nextSteps($approval->payload ?? []);
        $goal = $approval->payload['goal'] ?? 'this goal';
        $icp = \App\V2\Ai\Support\IcpSearchFilterParser::extractIcpSegment((string) (
            $approval->payload['icp_notes']
            ?? $approval->payload['audience']
            ?? $goal
        ));
        $filters = \App\V2\Ai\Support\IcpSearchFilterParser::fromGoal(
            (string) ($approval->payload['icp_notes'] ?? $approval->payload['audience'] ?? $goal),
            isset($approval->payload['geography']) ? (string) $approval->payload['geography'] : null,
            isset($approval->payload['target_count']) ? (int) $approval->payload['target_count'] : null,
        );

        return implode("\n", array_merge(
            [
                "I won't create a campaign for plan #{$approval->id} until LinkedIn returns a prospect list.",
                '',
                'I will search with prepared filters (not the full pitch):',
                '• Keywords: '.($filters['keywords'] ?? '—'),
                '• Title: '.($filters['title'] ?? 'any'),
                '• Location: '.($filters['location'] ?? 'any'),
                '• ICP focus: '.($icp !== '' ? $icp : '—'),
                '',
                'Next:',
            ],
            array_map(fn (string $step) => '• '.$step, $steps),
            [
                '',
                'Say "fetch prospects" or "discover 40" again — Soci retries with broader LinkedIn search variants and saves the list.',
            ],
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
            if ($existing->status === 'approved'
                && empty(data_get($existing->result, 'outreach_campaign_id'))
                && $verb === 'LAUNCH'
            ) {
                return implode("\n", [
                    "Plan #{$id} was approved but the outreach draft never finished.",
                    '',
                    "Send *LAUNCH {$id}* again to retry.",
                ]);
            }

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

        if ($this->isLinkedInPostApproval($approval) || $this->isCallManagerLaunchApproval($approval)) {
            $mentioned = ['linkedin'];
        }

        if ($this->hasFutureScheduledLinkedInPost($approval)) {
            return null;
        }

        if ($this->isContentRescheduleApproval($approval)) {
            return null;
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
            'prepare_linkedin_post',
            'prepare_call_manager_launch',
        ], true)) {
            return true;
        }

        $type = (string) ($approval->payload['type'] ?? '');

        if (in_array($type, ['draft_reply', 'competitor_harvest', 'campaign', 'linkedin_post', 'call_manager_launch'], true)) {
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
        return $pending->map(function (AiActionApproval $a) {
            $payload = is_array($a->payload) ? $a->payload : [];
            $funnel = $payload['funnel'] ?? null;
            if ($funnel === null && in_array($payload['type'] ?? '', ['strategy', 'campaign'], true)) {
                $user = User::query()->find($a->user_id);
                $funnel = $user
                    ? app(PlanFunnelService::class)->forPlan($payload, $user)
                    : [];
            }

            $labels = \App\V2\Ai\Support\ApprovalActionLabels::for($a->tool, $payload);

            return [
                'id' => $a->id,
                'tool' => $a->tool,
                'permission' => $a->permission,
                'status' => $a->status,
                'payload' => $payload,
                'funnel' => $funnel ?? [],
                'card_text' => $this->formatPlanCard($payload, $a->id, 'web', $a->tool),
                'actions' => [
                    'approve_label' => $labels['approve'],
                    'reject_label' => $labels['reject'],
                    'preview_label' => $labels['preview'],
                    'show_preview' => $labels['show_preview'],
                    'summary' => $labels['summary'],
                ],
                'created_at' => $a->created_at?->toIso8601String(),
            ];
        })->values()->all();
    }
}

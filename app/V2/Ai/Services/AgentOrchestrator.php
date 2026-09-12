<?php

namespace App\V2\Ai\Services;

use App\Ai\Agents\SociFusionAgent;
use App\Jobs\V2\ProcessWebAiChatJob;
use App\Models\AiActionApproval;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use App\V2\Ai\AgentContext;
use App\V2\Ai\Enums\AiAutonomyLevel;
use Illuminate\Support\Str;
use Throwable;

class AgentOrchestrator
{
    public function __construct(
        private readonly AiEmployeeSettingsService $settingsService,
        private readonly CommandCenterService $commandCenter,
        private readonly AutonomyContextService $autonomyContext,
        private readonly WebChatProcessingService $webChatProcessing,
        private readonly PromptObservabilityService $promptObservability,
    ) {}

    /**
     * @return array{
     *     conversation_id:int,
     *     reply:string,
     *     blocked?:bool,
     *     duplicate?:bool,
     *     pending_approvals?:list<array<string,mixed>>,
     *     latest_approval?:array<string,mixed>|null
     * }
     */
    public function handle(
        User $user,
        int $organizationId,
        string $message,
        string $channel = 'web',
        ?int $conversationId = null,
        ?int $channelIdentityId = null,
        ?string $providerMessageId = null,
    ): array {
        $prepared = $this->prepareTurn(
            user: $user,
            organizationId: $organizationId,
            message: $message,
            channel: $channel,
            conversationId: $conversationId,
            channelIdentityId: $channelIdentityId,
            providerMessageId: $providerMessageId,
        );

        if (isset($prepared['early'])) {
            return $prepared['early'];
        }

        /** @var AiConversation $conversation */
        $conversation = $prepared['conversation'];
        $promptMessage = (string) $prepared['prompt_message'];
        $settings = $prepared['settings'];

        AiMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $message,
            'provider_message_id' => $providerMessageId,
            'meta' => ['channel' => $channel],
        ]);

        return $this->runAgentAndPersistReply(
            user: $user,
            organizationId: $organizationId,
            conversation: $conversation,
            settings: $settings,
            channel: $channel,
            promptMessage: $promptMessage,
        );
    }

    /**
     * Web Command Center / widget: control commands stay sync; LLM turns are queued.
     *
     * @return array<string, mixed>
     */
    public function handleWeb(
        User $user,
        int $organizationId,
        string $message,
        ?int $conversationId = null,
    ): array {
        $queueEnabled = (bool) config('socifusion_ai.web_chat_queue', true);

        $prepared = $this->prepareTurn(
            user: $user,
            organizationId: $organizationId,
            message: $message,
            channel: 'web',
            conversationId: $conversationId,
        );

        if (isset($prepared['early'])) {
            $early = $prepared['early'];
            $early['status'] = ($early['blocked'] ?? false) || ($early['duplicate'] ?? false)
                ? 'done'
                : 'done';

            return $early;
        }

        /** @var AiConversation $conversation */
        $conversation = $prepared['conversation'];
        $promptMessage = (string) $prepared['prompt_message'];
        $settings = $prepared['settings'];

        if (! $queueEnabled) {
            AiMessage::query()->create([
                'conversation_id' => $conversation->id,
                'role' => 'user',
                'content' => $message,
                'meta' => ['channel' => 'web'],
            ]);

            $payload = $this->runAgentAndPersistReply(
                user: $user,
                organizationId: $organizationId,
                conversation: $conversation,
                settings: $settings,
                channel: 'web',
                promptMessage: $promptMessage,
            );
            $payload['status'] = 'done';

            return $payload;
        }

        $userMessage = AiMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $message,
            'meta' => ['channel' => 'web', 'queued' => true],
        ]);

        $this->webChatProcessing->markPending(
            $conversation,
            (int) $userMessage->id,
            $promptMessage,
            $organizationId,
        );

        ProcessWebAiChatJob::dispatch(
            $user->id,
            $organizationId,
            (int) $conversation->id,
            (int) $userMessage->id,
            $promptMessage,
        );

        return [
            'status' => 'queued',
            'pending' => true,
            'conversation_id' => (int) $conversation->id,
            'after_message_id' => (int) $userMessage->id,
            'user_message' => [
                'id' => (int) $userMessage->id,
                'role' => 'user',
                'content' => $message,
                'channel' => 'web',
                'created_at' => $userMessage->created_at?->toIso8601String(),
            ],
            'reply' => '',
            'blocked' => false,
            'autonomy_level' => (int) $settings->autonomy_level,
            'autonomy_label' => $this->autonomyContext->label((int) $settings->autonomy_level),
            'pending_approvals' => $this->commandCenter->serializeApprovals(
                $this->commandCenter->pendingApprovals($user, $organizationId)
            ),
            'latest_approval' => null,
        ];
    }

    /**
     * Queue worker continuation after the user message was already persisted.
     *
     * @return array<string, mixed>
     */
    public function continueQueuedWebTurn(
        User $user,
        int $organizationId,
        int $conversationId,
        int $userMessageId,
        string $promptMessage,
    ): array {
        $settings = $this->settingsService->for($user, $organizationId);

        if ($this->settingsService->isBlocked($settings)) {
            $conversation = AiConversation::query()->find($conversationId);
            if ($conversation) {
                AiMessage::query()->create([
                    'conversation_id' => $conversation->id,
                    'role' => 'assistant',
                    'content' => 'AI Employee is currently disabled for this workspace.',
                    'meta' => ['channel' => 'web', 'blocked' => true],
                ]);
            }

            return [
                'conversation_id' => $conversationId,
                'reply' => 'AI Employee is currently disabled for this workspace.',
                'blocked' => true,
                'pending_approvals' => [],
            ];
        }

        $conversation = AiConversation::query()
            ->whereKey($conversationId)
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->first();

        if (! $conversation) {
            return [
                'conversation_id' => $conversationId,
                'reply' => '',
                'blocked' => true,
                'pending_approvals' => [],
            ];
        }

        $userMessage = AiMessage::query()
            ->whereKey($userMessageId)
            ->where('conversation_id', $conversation->id)
            ->where('role', 'user')
            ->first();

        if (! $userMessage) {
            return [
                'conversation_id' => $conversation->id,
                'reply' => '',
                'blocked' => true,
                'pending_approvals' => [],
            ];
        }

        $processing = app(WebChatProcessingService::class);
        $alreadyReplied = AiMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->where('id', '>', $userMessage->id)
            ->get(['content', 'meta'])
            ->contains(fn (AiMessage $message) => $processing->isFinalAssistantReply(
                $message->content,
                $message->meta,
            ));

        if ($alreadyReplied) {
            $this->webChatProcessing->clearAll($conversation);

            return $this->payload($user, $organizationId, $conversation->id, '', null, $settings);
        }

        $this->webChatProcessing->start($conversation, 'Thinking…');
        $this->webChatProcessing->markAgentRunning($conversation);

        try {
            return $this->runAgentAndPersistReply(
                user: $user,
                organizationId: $organizationId,
                conversation: $conversation,
                settings: $settings,
                channel: 'web',
                promptMessage: $promptMessage,
            );
        } finally {
            $fresh = $conversation->fresh() ?? $conversation;
            if ($this->hasActiveWorkflowForConversation($fresh)) {
                $this->webChatProcessing->update(
                    $fresh,
                    'workflow',
                    'Workflow running — discovering prospects & staging outreach…',
                );
            } else {
                $this->webChatProcessing->clearAll($fresh);
            }
        }
    }

    public function failQueuedWebTurn(
        User $user,
        int $organizationId,
        int $conversationId,
        int $userMessageId,
        ?Throwable $exception = null,
    ): void {
        $conversation = AiConversation::query()
            ->whereKey($conversationId)
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->first();

        if ($conversation) {
            $this->webChatProcessing->clearAll($conversation);
        }

        if (! $conversation) {
            return;
        }

        $alreadyReplied = AiMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->where('id', '>', $userMessageId)
            ->get(['content', 'meta'])
            ->contains(fn (AiMessage $message) => app(WebChatProcessingService::class)->isFinalAssistantReply(
                $message->content,
                $message->meta,
            ));

        if ($alreadyReplied) {
            return;
        }

        if ($exception !== null) {
            report($exception);
        }

        AiMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'That took too long or hit an error while processing. Please try again — if it keeps failing, check the queue worker is running.',
            'meta' => [
                'channel' => 'web',
                'queued_job_failed' => true,
                'error' => $exception?->getMessage(),
            ],
        ]);
    }

    /**
     * @return array{
     *     early?: array<string, mixed>,
     *     conversation?: AiConversation,
     *     prompt_message?: string,
     *     settings?: \App\Models\AiEmployeeSetting
     * }
     */
    private function prepareTurn(
        User $user,
        int $organizationId,
        string $message,
        string $channel,
        ?int $conversationId = null,
        ?int $channelIdentityId = null,
        ?string $providerMessageId = null,
    ): array {
        $settings = $this->settingsService->for($user, $organizationId);

        if ($this->settingsService->isBlocked($settings)) {
            return [
                'early' => [
                    'conversation_id' => $conversationId ?? 0,
                    'reply' => 'AI Employee is currently disabled for this workspace.',
                    'blocked' => true,
                    'pending_approvals' => [],
                    'status' => 'done',
                ],
            ];
        }

        $conversation = $this->commandCenter->conversation($user, $organizationId, $channelIdentityId);

        if ($conversationId) {
            $requested = AiConversation::query()
                ->whereKey($conversationId)
                ->where('user_id', $user->id)
                ->where('organization_id', $organizationId)
                ->first();

            if ($requested) {
                $conversation = $requested;
            }
        }

        if ($providerMessageId) {
            $exists = AiMessage::query()
                ->where('conversation_id', $conversation->id)
                ->where('provider_message_id', $providerMessageId)
                ->exists();

            if ($exists) {
                return [
                    'early' => [
                        'conversation_id' => $conversation->id,
                        'reply' => '',
                        'blocked' => false,
                        'duplicate' => true,
                        'pending_approvals' => $this->commandCenter->serializeApprovals(
                            $this->commandCenter->pendingApprovals($user, $organizationId)
                        ),
                        'status' => 'done',
                    ],
                ];
            }
        }

        $control = $this->commandCenter->handleControlCommand($user, $organizationId, $message);
        $promptMessage = $message;
        $intent = app(UserTurnIntentService::class);
        $preStatus = $this->promptObservability->classifyPreAgentPrompt($message, $control, [
            'is_informational' => $intent->isInformational($message),
            'is_discovery' => $intent->isProspectDiscoveryRequest($message),
            'is_outreach' => $intent->isOutreachCommand($message),
            'is_setup_only' => $intent->wantsCampaignSetupOnly($message),
            'has_pending_approvals' => $this->commandCenter->pendingApprovals($user, $organizationId)->isNotEmpty(),
        ]);
        $this->promptObservability->logPromptEvent(
            $user,
            $organizationId,
            $conversation,
            $preStatus,
            [
                'message' => Str::limit($message, 500, ''),
                'channel' => $channel,
                'control' => $control ? ($control['decision'] ?? ($control['handled'] ?? false ? 'handled' : 'rewrite')) : null,
            ],
            [
                'rewrite' => $control['rewrite'] ?? null,
            ],
        );

        if ($control && ($control['handled'] ?? false)) {
            AiMessage::query()->create([
                'conversation_id' => $conversation->id,
                'role' => 'user',
                'content' => $message,
                'provider_message_id' => $providerMessageId,
                'meta' => ['channel' => $channel],
            ]);

            $reply = (string) ($control['reply'] ?? '');

            AiMessage::query()->create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => $reply,
                'meta' => ['channel' => $channel, 'control' => $control['decision'] ?? 'command'],
            ]);

            $payload = $this->payload(
                $user,
                $organizationId,
                $conversation->id,
                $reply,
                isset($control['approval']) && $control['approval']->status === 'pending'
                    ? $control['approval']
                    : null,
            );
            $payload['status'] = 'done';

            return ['early' => $payload];
        }

        $inboxPreflight = app(InboxReplyTurnPreflightService::class)->tryHandle(
            $user,
            $organizationId,
            $conversation,
            $message,
            $channel,
        );

        if ($inboxPreflight && ($inboxPreflight['handled'] ?? false)) {
            AiMessage::query()->create([
                'conversation_id' => $conversation->id,
                'role' => 'user',
                'content' => $message,
                'provider_message_id' => $providerMessageId,
                'meta' => ['channel' => $channel],
            ]);

            $reply = (string) ($inboxPreflight['reply'] ?? '');
            $approval = $inboxPreflight['approval'] ?? null;

            AiMessage::query()->create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => $reply,
                'meta' => array_filter([
                    'channel' => $channel,
                    'control' => 'inbox_reply_preflight',
                    'approval_id' => $approval instanceof AiActionApproval ? $approval->id : null,
                ]),
            ]);

            $payload = $this->payload(
                $user,
                $organizationId,
                $conversation->id,
                $reply,
                $approval instanceof AiActionApproval && $approval->status === 'pending'
                    ? $approval
                    : null,
            );
            $payload['status'] = 'done';

            return ['early' => $payload];
        }

        $campaignPreflight = app(CampaignOutreachPreflightService::class)->tryHandle(
            $user,
            $organizationId,
            $conversation,
            $message,
        );

        if ($campaignPreflight && ($campaignPreflight['handled'] ?? false)) {
            AiMessage::query()->create([
                'conversation_id' => $conversation->id,
                'role' => 'user',
                'content' => $message,
                'provider_message_id' => $providerMessageId,
                'meta' => ['channel' => $channel],
            ]);

            $reply = (string) ($campaignPreflight['reply'] ?? '');
            $approval = $campaignPreflight['approval'] ?? null;

            if ($approval instanceof AiActionApproval && $channel === 'whatsapp') {
                app(CommandCenterPushService::class)->mirrorToWhatsApp(
                    $user,
                    $organizationId,
                    $reply,
                    $approval->id,
                    ['source' => 'campaign_preflight', 'tool' => $approval->tool],
                );
            }

            AiMessage::query()->create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => $reply,
                'meta' => array_filter([
                    'channel' => $channel,
                    'control' => 'campaign_preflight',
                    'approval_id' => $approval instanceof AiActionApproval ? $approval->id : null,
                ]),
            ]);

            $payload = $this->payload(
                $user,
                $organizationId,
                $conversation->id,
                $reply,
                $approval instanceof AiActionApproval && $approval->status === 'pending'
                    ? $approval
                    : null,
            );
            $payload['status'] = 'done';

            return ['early' => $payload];
        }

        if ($control && ! empty($control['rewrite'])) {
            $promptMessage = (string) $control['rewrite'];
        }

        $settings = $this->settingsService->for($user, $organizationId);
        $modeChangeNote = $this->autonomyContext->syncConversationMode($conversation, $settings);
        $promptMessage = $this->autonomyContext->promptPrefix($settings, $modeChangeNote).$promptMessage;

        return [
            'conversation' => $conversation,
            'prompt_message' => $promptMessage,
            'settings' => $settings,
        ];
    }

    /**
     * @param  \App\Models\AiEmployeeSetting  $settings
     * @return array<string, mixed>
     */
    private function runAgentAndPersistReply(
        User $user,
        int $organizationId,
        AiConversation $conversation,
        $settings,
        string $channel,
        string $promptMessage,
    ): array {
        $context = new AgentContext(
            user: $user,
            organizationId: $organizationId,
            settings: $settings,
            conversation: $conversation,
            channel: $channel,
        );

        $latestApproval = null;
        $ledger = app(TurnExecutionLedger::class);
        $ledger->reset();
        $plan = app(TurnPlanBuilderService::class)->build($user, $organizationId, $promptMessage, $settings);
        if ($plan['workflow_eligible'] ?? false) {
            $workflowRun = app(WorkflowRuntimeService::class)->start(
                $user,
                $organizationId,
                $plan,
                $conversation,
            );
            $plan['workflow_run_id'] = $workflowRun->id;
            $plan['workflow_baseline_state'] = $workflowRun->meta['baseline_state'] ?? null;
        }
        app(TurnPlanContext::class)->set($plan);
        if (($plan['required_outcome'] ?? '') === 'clarify') {
            $reason = trim((string) ($plan['constraints']['clarification_reason'] ?? 'The request needs clarification before any action.'));
            $promptMessage = '[Turn plan: clarification required — '.$reason
                .'. Ask the user to specify which resource they mean. Do not mutate data or execute outreach/delete tools.]'
                ."\n\n".$promptMessage;
        } elseif (($plan['required_outcome'] ?? '') === 'status_only') {
            $replyCheck = (bool) preg_match(
                '/\b(reply|replies|replied|respond(?:ed|s)?|response|heard back|got back|inbox)\b/i',
                $promptMessage,
            );
            $promptMessage = ($replyCheck
                ? '[Turn plan: reply check — call get_attention_queue first (unified inbox is the source of truth for replies). '
                    .'Use get_campaign_stats only for send/completion counts on a named campaign. If they disagree, trust the inbox. '
                    .'Do not discover, draft campaigns, send, or delete.]'
                : '[Turn plan: read-only — answer from search_activity, search_prospects, get_campaign_stats, get_attention_queue, or activity tools. Do not discover, draft campaigns, send, or delete.]')
                ."\n\n".$promptMessage;
        } elseif ($plan['planning_degraded'] ?? false) {
            $promptMessage = '[Turn plan: semantic planner unavailable — using conservative fallback. Prefer read/search tools; do not mutate unless the user message clearly requests delete or outreach and policy allows it.]'
                ."\n\n".$promptMessage;
        }
        $stateContext = app(TurnPlanStateEvaluationService::class)
            ->promptContext(is_array($plan['state_evaluation'] ?? null) ? $plan['state_evaluation'] : []);
        if ($stateContext !== null) {
            $promptMessage = $stateContext."\n\n".$promptMessage;
        }
        $awareness = app(CommandCenterAwarenessService::class)->promptBlock($user, $organizationId);
        if ($awareness !== '') {
            $promptMessage = $awareness."\n\n".$promptMessage;
        }
        if (isset($plan['workflow_run_id'])) {
            $promptMessage = '[Workflow run #'.$plan['workflow_run_id']
                .' owns orchestration in the background — do NOT call discover_prospects or draft_campaign_plan. '
                .'Discovery and campaign staging run asynchronously; the chat snapshot may show 0 until the worker finishes. '
                .'Tell the user discovery is in progress and they will get a follow-up here when the list is saved or a plan is ready for Review & Launch.]'
                ."\n\n".$promptMessage;
        }
        $verifier = app(PostExecutionVerifierService::class);
        $beforeSnapshot = $verifier->snapshot($user, $organizationId);
        $progress = app(WebChatTurnProgressService::class);
        $progress->bind($channel === 'web' ? $context : null);
        $postedProgressFinal = false;
        $reply = '';

        $workflowDiscoveryAck = isset($plan['workflow_run_id'])
            && in_array((string) ($plan['required_outcome'] ?? ''), ['find_only', 'setup_only'], true);

        if ($workflowDiscoveryAck) {
            $reply = app(WorkflowDiscoveryAckService::class)->build($user, $organizationId, $plan);
        } else {
            $commandCenterResearch = app(CommandCenterResearchService::class);
            if ($commandCenterResearch->shouldResearch($promptMessage)) {
                if ($channel === 'web') {
                    $this->webChatProcessing->update($conversation, 'research', 'Researching link & building context…');
                }
                $promptMessage = $commandCenterResearch->enrichTurn($conversation, $promptMessage, $promptMessage);
            }
        }

        try {
            if (! $workflowDiscoveryAck) {
                $providers = app(AiProviderChain::class)->forAgent();
                $response = (new SociFusionAgent($context))->prompt(
                    $promptMessage,
                    provider: $providers !== [] ? $providers : null,
                );
                $reply = trim((string) $response);

                if ($ledger->ownsTurnResult()) {
                    $reply = $ledger->report();
                    $latestApproval = null;
                } else {
                    $latestApproval = $this->commandCenter->pendingApprovals($user, $organizationId)->first();
                    $latestApproval = $this->maybeAutoLaunchOutreachPlan(
                        $user,
                        $organizationId,
                        $reply,
                        $latestApproval,
                        $promptMessage,
                    );
                    $latestApproval = $this->maybeAutoSendDraftReply(
                        $user,
                        $organizationId,
                        $reply,
                        $latestApproval,
                    );
                }
            }
        } catch (Throwable $e) {
            report($e);
            try {
                app(AiErrorLogService::class)->capture(
                    $e,
                    'agent_turn',
                    $user,
                    $organizationId,
                    $conversation,
                    $channel,
                    $promptMessage,
                    [
                        'autonomy_level' => $settings->autonomy_level ?? null,
                        'employee_name' => $settings->employee_name ?? null,
                    ],
                );
            } catch (Throwable $logError) {
                report($logError);
            }
            $reply = $this->userFacingAgentError($e);
            if ($ledger->ownsTurnResult()) {
                $reply = $ledger->report();
            }
        } finally {
            $postedProgressFinal = $progress->postedFinalReply();
            $progress->bind(null);
            app(TurnPlanContext::class)->clear();
        }

        if ($ledger->ownsTurnResult()) {
            $reply = $ledger->report();
        }

        if ($reply === '') {
            $reply = 'Done.';
        }

        $afterSnapshot = $verifier->snapshot($user, $organizationId);
        $verification = $verifier->verify($user, $organizationId, $plan, $beforeSnapshot, $afterSnapshot);
        app(AiActivityLogService::class)->record(
            $user,
            $organizationId,
            $conversation->id,
            'orchestrator',
            'turn_verified',
            'turn',
            (string) $conversation->id,
            $verification,
            $promptMessage,
        );
        if (! ($verification['ok'] ?? false)) {
            $reply = trim($reply."\n\nVerification warning: ".implode(' ', $verification['warnings'] ?? []));
        }

        $fallback = $this->promptObservability->enforceClarifierFallback($promptMessage, $reply);
        if ($fallback['used_fallback']) {
            $this->promptObservability->logPromptEvent(
                $user,
                $organizationId,
                $conversation,
                'fallback_clarifier',
                [
                    'message' => Str::limit($promptMessage, 500, ''),
                    'reason' => $fallback['reason'],
                    'raw_reply' => Str::limit($reply, 300, ''),
                ],
                [
                    'reply' => Str::limit($fallback['reply'], 500, ''),
                ],
            );
            $reply = $fallback['reply'];
        }

        if ($latestApproval && $channel === 'whatsapp' && $latestApproval->status === 'pending') {
            $card = $this->commandCenter->formatPlanCard(
                $latestApproval->payload ?? [],
                $latestApproval->id,
                'whatsapp'
            );
            if (! str_contains($reply, (string) $latestApproval->id)) {
                $reply = trim($reply."\n\n".$card);
            }
        }

        if (! $postedProgressFinal) {
            AiMessage::query()->create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => $reply,
                'meta' => [
                    'channel' => $channel,
                    'approval_id' => $latestApproval?->id,
                ],
            ]);
        }

        return $this->payload($user, $organizationId, $conversation->id, $reply, $latestApproval, $settings);
    }

    private function hasActiveWorkflowForConversation(\App\Models\AiConversation $conversation): bool
    {
        return \App\Models\AiWorkflowRun::query()
            ->where('conversation_id', $conversation->id)
            ->whereIn('status', ['running', 'waiting', 'planned', 'approved'])
            ->exists();
    }

    private function userFacingAgentError(Throwable $e): string
    {
        $blob = strtolower($e->getMessage().' '.$e->getPrevious()?->getMessage());

        if (str_contains($blob, 'no credits') || str_contains($blob, 'insufficient_quota') || str_contains($blob, 'billing')) {
            return 'Soci could not finish that — the OpenAI account has no credits left. Add credits at platform.openai.com, or add an OPENROUTER_API_KEY so Soci can switch models, then send the message again.';
        }

        if (str_contains($blob, 'rate limit') || str_contains($blob, '429')) {
            return 'Soci hit a model limit and no backup model is configured. Add an OPENROUTER_API_KEY (and credits) so the next attempt can switch providers, then try again.';
        }

        return 'I hit an error processing that. Please try again in a moment.';
    }

    private function maybeAutoLaunchOutreachPlan(
        User $user,
        int $organizationId,
        string &$reply,
        ?AiActionApproval $latestApproval,
        string $promptMessage = '',
    ): ?AiActionApproval {
        if (! $latestApproval || $latestApproval->status !== 'pending') {
            return $latestApproval;
        }

        if (app(UserTurnIntentService::class)->wantsCampaignSetupOnly($promptMessage)) {
            return $latestApproval;
        }

        if ((bool) data_get($latestApproval->payload, 'setup_only')) {
            return $latestApproval;
        }

        $settings = $this->settingsService->for($user, $organizationId);
        $autonomy = AiAutonomyLevel::tryFrom((int) $settings->autonomy_level) ?? AiAutonomyLevel::Assisted;
        if ($autonomy->value < AiAutonomyLevel::Autopilot->value) {
            return $latestApproval;
        }

        if (! $this->commandCenter->isAutoLaunchApproval($latestApproval)) {
            return $latestApproval;
        }

        $this->commandCenter->attachAutoAudience($user, $organizationId, $latestApproval);
        $launch = $this->commandCenter->handleControlCommand(
            $user,
            $organizationId,
            'LAUNCH '.$latestApproval->id,
        );

        if (! ($launch['handled'] ?? false) || ($launch['decision'] ?? '') !== 'approve') {
            return $latestApproval->fresh();
        }

        $reply = trim($reply."\n\n".(string) ($launch['reply'] ?? ''));
        $approved = $launch['approval'] ?? null;

        return $approved instanceof AiActionApproval ? $approved : $latestApproval->fresh();
    }

    private function maybeAutoSendDraftReply(
        User $user,
        int $organizationId,
        string &$reply,
        ?AiActionApproval $latestApproval,
    ): ?AiActionApproval {
        if (! $latestApproval || $latestApproval->status !== 'pending') {
            return $latestApproval;
        }

        if ($latestApproval->tool !== 'draft_reply') {
            return $latestApproval;
        }

        $settings = $this->settingsService->for($user, $organizationId);
        $autonomy = AiAutonomyLevel::tryFrom((int) $settings->autonomy_level) ?? AiAutonomyLevel::Assisted;
        if ($autonomy->value < AiAutonomyLevel::Autopilot->value) {
            return $latestApproval;
        }

        try {
            $result = app(ReplySendFromPlanService::class)->sendFromApproval($latestApproval, $user);
            $reply = trim($reply."\n\n✅ ".($result['message'] ?? 'Reply sent.'));
        } catch (\Throwable $e) {
            report($e);

            return $latestApproval;
        }

        return $latestApproval->fresh();
    }

    /**
     * @return array{
     *     conversation_id:int,
     *     reply:string,
     *     blocked:bool,
     *     pending_approvals:list<array<string,mixed>>,
     *     latest_approval:array<string,mixed>|null
     * }
     */
    private function payload(
        User $user,
        int $organizationId,
        int $conversationId,
        string $reply,
        ?AiActionApproval $latest = null,
        ?\App\Models\AiEmployeeSetting $settings = null,
    ): array {
        $settings ??= $this->settingsService->for($user, $organizationId);
        $pending = $this->commandCenter->pendingApprovals($user, $organizationId);
        $serialized = $this->commandCenter->serializeApprovals($pending);

        return [
            'conversation_id' => $conversationId,
            'reply' => $reply,
            'blocked' => false,
            'autonomy_level' => (int) $settings->autonomy_level,
            'autonomy_label' => $this->autonomyContext->label((int) $settings->autonomy_level),
            'pending_approvals' => $serialized,
            'latest_approval' => $latest && $latest->status === 'pending'
                ? (collect($serialized)->firstWhere('id', $latest->id) ?? [
                    'id' => $latest->id,
                    'status' => $latest->status,
                    'tool' => $latest->tool,
                    'payload' => $latest->payload,
                    'card_text' => $this->commandCenter->formatPlanCard($latest->payload ?? [], $latest->id, 'web', $latest->tool),
                ])
                : null,
        ];
    }
}

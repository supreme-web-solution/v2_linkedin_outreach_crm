<?php

namespace App\V2\Ai\Services;

use App\Models\AiActionApproval;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use App\V2\Ai\Enums\AiAutonomyLevel;
use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Support\PlanLeadList;
use App\V2\Outreach\OutreachChannelRegistry;
use Illuminate\Support\Str;

/**
 * Cold one-shot outbound: email / LinkedIn DM / WhatsApp / Telegram / Instagram / X
 * without requiring an existing Unified Inbox thread.
 */
class OneShotOutboundCommandCenterService
{
    public function __construct(
        private readonly ActionApprovalService $approvals,
        private readonly CommandCenterService $commandCenter,
        private readonly AiEmployeeSettingsService $settingsService,
        private readonly ProspectAudienceResolverService $audienceResolver,
        private readonly PlanFunnelService $funnel,
        private readonly OutboundMessageComposerService $composer,
        private readonly UserTurnIntentService $intent,
        private readonly SemanticTurnPlanService $semanticPlanner,
        private readonly OutboundDraftQualityService $draftQuality,
        private readonly ColdOutboundIdentityGuardService $identityGuard,
    ) {}

    /**
     * @return array{handled:bool, reply?:string, approval?:AiActionApproval|null}|null
     */
    public function tryHandle(
        User $user,
        int $organizationId,
        AiConversation $conversation,
        string $message,
        string $surface = 'web',
    ): ?array {
        $decision = $this->resolveColdOutboundIntent($user, $organizationId, $conversation, $message);
        if (! ($decision['cold_one_shot'] ?? false)) {
            return null;
        }

        $identity = $this->intent->extractColdOutboundIdentity($message);
        if (($identity['channel'] ?? null) === null) {
            $identity = $this->identityFromPriorPlan($conversation);
        }
        if (($identity['channel'] ?? null) === null) {
            return null;
        }

        try {
            return $this->stage(
                $user,
                $organizationId,
                $conversation,
                $message,
                $identity,
                $surface,
                (bool) ($decision['recipient_correction'] ?? false),
                is_string($decision['handoff_brief'] ?? null) ? (string) $decision['handoff_brief'] : null,
                (bool) ($decision['message_correction'] ?? false),
                is_string($decision['offer_override'] ?? null) ? (string) $decision['offer_override'] : null,
            );
        } catch (\Throwable $e) {
            report($e);

            return [
                'handled' => true,
                'reply' => 'I could not stage that '.$this->label($identity['channel']).' send: '.$e->getMessage(),
                'approval' => null,
            ];
        }
    }

    /**
     * Prefer LLM semantic intent; keyword heuristics only when the planner is unavailable.
     *
     * @return array{
     *     cold_one_shot:bool,
     *     recipient_correction:bool,
     *     message_correction:bool,
     *     offer_override:?string,
     *     handoff_brief:?string
     * }
     */
    private function resolveColdOutboundIntent(
        User $user,
        int $organizationId,
        AiConversation $conversation,
        string $message,
    ): array {
        $interpreted = $this->semanticPlanner->interpret($user, $organizationId, $message, $conversation);
        if (is_array($interpreted)) {
            $semantic = is_array($interpreted['semantic'] ?? null) ? $interpreted['semantic'] : [];

            if ((bool) ($semantic['inbox_reply'] ?? false)) {
                return [
                    'cold_one_shot' => false,
                    'recipient_correction' => false,
                    'message_correction' => false,
                    'offer_override' => null,
                    'handoff_brief' => null,
                ];
            }

            return [
                'cold_one_shot' => (bool) ($semantic['cold_one_shot'] ?? false)
                    || (bool) ($semantic['recipient_correction'] ?? false)
                    || (bool) ($semantic['message_correction'] ?? false),
                'recipient_correction' => (bool) ($semantic['recipient_correction'] ?? false),
                'message_correction' => (bool) ($semantic['message_correction'] ?? false),
                'offer_override' => isset($semantic['offer_override']) && is_string($semantic['offer_override'])
                    ? trim($semantic['offer_override'])
                    : null,
                'handoff_brief' => isset($semantic['handoff_brief']) && is_string($semantic['handoff_brief'])
                    ? trim($semantic['handoff_brief'])
                    : null,
            ];
        }

        // Planner unavailable — only structural identity asks; rewrites need the semantic planner + thread.
        return [
            'cold_one_shot' => $this->intent->isColdOutboundRequest($message),
            'recipient_correction' => $this->intent->isOutboundRecipientCorrection($message),
            'message_correction' => false,
            'offer_override' => null,
            'handoff_brief' => null,
        ];
    }

    /**
     * @param  array{
     *     channel: string,
     *     email?: ?string,
     *     phone?: ?string,
     *     linkedin_url?: ?string,
     *     instagram_handle?: ?string,
     *     telegram_handle?: ?string,
     *     twitter_handle?: ?string,
     *     research_url?: ?string,
     *     display_name?: ?string
     * }  $identity
     * @return array{handled:bool, reply:string, approval?:AiActionApproval|null}
     */
    public function stage(
        User $user,
        int $organizationId,
        AiConversation $conversation,
        string $message,
        array $identity,
        string $surface = 'web',
        ?bool $isCorrection = null,
        ?string $handoffBrief = null,
        ?bool $messageCorrection = null,
        ?string $offerOverride = null,
    ): array {
        $settings = $this->settingsService->for($user, $organizationId);
        if ($this->settingsService->isBlocked($settings)) {
            return [
                'handled' => true,
                'reply' => 'AI Employee is currently disabled for this workspace.',
                'approval' => null,
            ];
        }

        $isCorrection ??= $this->intent->isOutboundRecipientCorrection($message);
        $messageCorrection ??= false;
        $offerOverride = is_string($offerOverride) ? trim($offerOverride) : '';
        $prior = $this->priorColdOutboundPlan($conversation);

        $identityCheck = $this->identityGuard->inspect($user, $organizationId, $identity);
        if ($identityCheck['suggest_draft_reply'] && ($identityCheck['inbox_conversation_id'] ?? null)) {
            return [
                'handled' => true,
                'reply' => implode("\n\n", array_filter([
                    'This contact already has a Unified Inbox thread — cold one-shot would duplicate the conversation.',
                    'Use **draft_reply** on conversation **'.$identityCheck['inbox_conversation_id'].'** (or open the inbox) so Soci keeps full thread context.',
                    ! empty($identityCheck['pending_approval_id'])
                        ? 'There is also a pending approval #'.$identityCheck['pending_approval_id'].' for this contact.'
                        : null,
                ])),
                'approval' => null,
            ];
        }
        if (! empty($identityCheck['pending_approval_id'])) {
            $pending = \App\Models\AiActionApproval::query()->find((int) $identityCheck['pending_approval_id']);
            if ($pending instanceof AiActionApproval && $pending->status === 'pending') {
                $card = $this->commandCenter->formatPlanCard(
                    is_array($pending->payload) ? $pending->payload : [],
                    $pending->id,
                    $surface,
                    $pending->tool,
                );

                return [
                    'handled' => true,
                    'reply' => implode("\n\n", [
                        'A pending cold outbound for this contact already exists — reuse it instead of staging a duplicate.',
                        $card,
                        'Say **LAUNCH '.$pending->id.'** to send, or ask me to rewrite the draft.',
                    ]),
                    'approval' => $pending,
                ];
            }
        }

        $channel = (string) $identity['channel'];
        $label = $this->label($channel);
        $channelToken = $channel === 'twitter' ? 'twitter' : $channel;

        $freshResearchUrl = trim((string) ($identity['research_url'] ?? ''));
        $researchUrl = $freshResearchUrl;
        if ($researchUrl === '' && is_array($prior)) {
            $researchUrl = trim((string) ($prior['research_url'] ?? ''));
        }

        // Always re-scrape when the user pasted a research URL or asked to rewrite —
        // never trust a thin/stale prior research_notes blob for email personalization.
        $forceFreshResearch = $freshResearchUrl !== '' || $messageCorrection;
        $researchNotes = '';
        if ($researchUrl !== '') {
            $sameUrl = ! $forceFreshResearch
                && is_array($prior)
                && trim((string) ($prior['research_url'] ?? '')) === $researchUrl
                && $this->composer->researchIsSubstantial((string) ($prior['research_notes'] ?? ''));
            $researchNotes = $sameUrl
                ? trim((string) $prior['research_notes'])
                : $this->composer->researchUrl($researchUrl);
        } elseif (is_array($prior) && $this->composer->researchIsSubstantial((string) ($prior['research_notes'] ?? ''))) {
            $researchNotes = trim((string) $prior['research_notes']);
            $researchUrl = trim((string) ($prior['research_url'] ?? ''));
        }

        // Hard research gate: URL provided but unreadable — do not stage pretend-personalized copy.
        if ($researchUrl !== '' && ! $this->draftQuality->researchGateAllowsDraft($researchUrl, $researchNotes)) {
            return [
                'handled' => true,
                'reply' => implode("\n\n", [
                    "I could not read enough from **{$researchUrl}** to personalize safely.",
                    'I will not stage a “researched” draft from thin/empty page text.',
                    'Try again with a public page, paste a short brief about them, or send without a research URL if you only want a simple intro.',
                    ! empty($identityCheck['warnings']) ? implode("\n", $identityCheck['warnings']) : null,
                ]),
                'approval' => null,
            ];
        }

        $composeBrief = trim((string) ($handoffBrief ?: $message));
        $priorDraft = is_array($prior) ? trim((string) ($prior['message'] ?? '')) : '';
        $priorRecipient = is_array($prior) ? $this->priorRecipientLabel($prior) : null;
        $recipient = $this->recipientLabel($identity);
        $recipientChanged = $priorRecipient !== null
            && strcasecmp(trim($priorRecipient), trim($recipient)) !== 0;

        // Same address again is NOT a recipient correction — never keep a vague prior draft.
        if ($isCorrection && ! $recipientChanged) {
            $isCorrection = false;
        }

        $reusePriorDraft = $isCorrection
            && ! $messageCorrection
            && $recipientChanged
            && $freshResearchUrl === ''
            && $priorDraft !== '';

        $composed = ['body' => '', 'subject' => null];
        if ($reusePriorDraft) {
            $composed['body'] = $this->retargetDraft($priorDraft, $identity);
            $composed['subject'] = is_array($prior) ? ($prior['subject'] ?? null) : null;
        } else {
            $composed = $this->composer->compose(
                $user,
                $organizationId,
                $channel,
                $composeBrief,
                $identity,
                $researchNotes,
                $offerOverride !== '' ? $offerOverride : null,
                ($messageCorrection && $priorDraft !== '') ? $priorDraft : null,
                $this->recentThread($conversation),
            );
        }

        $draft = trim((string) ($composed['body'] ?? ''));
        if ($draft === '' && $researchNotes !== '') {
            $composed = $this->composer->compose(
                $user,
                $organizationId,
                $channel,
                $composeBrief,
                $identity,
                $researchNotes,
                $offerOverride !== '' ? $offerOverride : null,
                null,
                $this->recentThread($conversation),
            );
            $draft = trim((string) ($composed['body'] ?? ''));
        }

        $quality = $this->draftQuality->evaluate(
            $channel,
            $draft,
            $researchNotes,
            $composeBrief,
            $researchUrl !== '' ? $researchUrl : null,
        );

        $shouldRewrite = $draft !== ''
            && ! ($quality['pass'] ?? false)
            && ($quality['source'] ?? '') === 'laravel_ai'
            && $this->draftQuality->shouldHardBlockStaging(
                $quality,
                $channel,
                $researchNotes,
                $researchUrl !== '' ? $researchUrl : null,
            );

        if ($shouldRewrite) {
            // One rewrite pass with quality issues as guidance (still domain-agnostic).
            $rewriteBrief = $composeBrief."\n\nQuality issues to fix:\n- ".implode("\n- ", $quality['issues'] ?: ['Make the draft grounded and non-generic.']);
            $composed = $this->composer->compose(
                $user,
                $organizationId,
                $channel,
                $rewriteBrief,
                $identity,
                $researchNotes,
                $offerOverride !== '' ? $offerOverride : null,
                $draft,
                $this->recentThread($conversation),
            );
            $draft = trim((string) ($composed['body'] ?? $draft));
            $quality = $this->draftQuality->evaluate(
                $channel,
                $draft,
                $researchNotes,
                $composeBrief,
                $researchUrl !== '' ? $researchUrl : null,
            );
        }

        $qualityHardFail = $draft === ''
            || (
                ! ($quality['pass'] ?? false)
                && in_array((string) ($quality['source'] ?? ''), ['laravel_ai', 'copy_guard', 'research_gate'], true)
                && $this->draftQuality->shouldHardBlockStaging(
                    $quality,
                    $channel,
                    $researchNotes,
                    $researchUrl !== '' ? $researchUrl : null,
                )
            );
        $qualityAdvisory = $draft !== ''
            && ! ($quality['pass'] ?? false)
            && ! $qualityHardFail
            && in_array((string) ($quality['source'] ?? ''), ['laravel_ai', 'fallback'], true);

        if ($draft === '' || $qualityHardFail) {
            return [
                'handled' => true,
                'reply' => implode("\n\n", array_filter([
                    $draft === ''
                        ? 'I could not produce a recipient-facing draft yet.'
                        : 'The draft failed the quality gate (not grounded / too generic for Launch).',
                    ! empty($quality['issues']) ? 'Issues: '.implode('; ', $quality['issues']) : null,
                    $researchUrl !== '' ? 'Research URL: '.$researchUrl : null,
                    'Tell me what to emphasize, paste notes about them, or ask me to rewrite with a clearer angle.',
                ])),
                'approval' => null,
            ];
        }

        $emailSteps = $channel === 'email'
            ? [
                'Single contact — no prior inbox thread required',
                'Email: research their world, personalize, soft value pitch, earn a reply',
                'Pitch only what this workspace sells — never invent products',
            ]
            : [
                'Single contact — no prior inbox thread required',
                'Conversation-first DM: earn a reply, no hard pitch',
                'Pitch only what this workspace sells — never invent products',
            ];

        $researchFacts = $this->draftQuality->researchFactBullets($researchNotes);
        $plan = [
            'type' => 'campaign',
            'goal' => "One-shot {$label} to {$recipient}",
            'campaign_name' => "One-shot · {$label} · {$recipient}",
            'audience' => $recipient,
            'icp_notes' => $composeBrief !== '' ? $composeBrief : trim($message),
            'target_count' => 1,
            'preferred_channels' => $channelToken,
            'channels' => $channelToken,
            'primary_channel' => $channel,
            'single_channel_only' => true,
            'one_shot' => true,
            'one_time' => true,
            'pause_on_reply' => true,
            'personalize_before_send' => $draft === '',
            'message' => $draft,
            'subject' => $channel === 'email'
                ? \App\V2\Ai\Support\EmailOutboundFormat::normalizeSubject(
                    trim((string) ($composed['subject'] ?? '')),
                    is_string($researchNotes) ? $researchNotes : null,
                )
                : null,
            'sequence' => ["{$label} — one personalized message", 'Pause on reply — handle in inbox'],
            'steps' => $emailSteps,
            'status' => 'awaiting_review',
            'pipeline_status' => 'awaiting_approval',
            'source' => 'cold_outbound',
            'recipient_correction' => $isCorrection && $recipientChanged,
            'message_correction' => $messageCorrection,
            'offer_override' => $offerOverride !== '' ? $offerOverride : null,
            'prior_recipient' => $recipientChanged ? $priorRecipient : null,
            'contact_email' => $identity['email'] ?? null,
            'contact_phone' => $identity['phone'] ?? null,
            'linkedin_url' => $identity['linkedin_url'] ?? null,
            'profile_url' => $identity['linkedin_url'] ?? null,
            'instagram_handle' => $identity['instagram_handle'] ?? null,
            'instagram_url' => ! empty($identity['instagram_handle'])
                ? 'https://www.instagram.com/'.ltrim((string) $identity['instagram_handle'], '@')
                : null,
            'telegram_handle' => $identity['telegram_handle'] ?? null,
            'twitter_handle' => $identity['twitter_handle'] ?? null,
            'research_url' => $researchUrl !== '' ? $researchUrl : null,
            'research_notes' => $researchNotes !== '' ? $researchNotes : null,
            'research_facts' => $researchFacts,
            'evidence' => $researchFacts,
            'quality' => [
                'score' => $quality['score'] ?? null,
                'pass' => $quality['pass'] ?? null,
                'advisory' => $qualityAdvisory,
                'block_severity' => $qualityAdvisory
                    ? 'advisory'
                    : (string) ($quality['block_severity'] ?? (($quality['pass'] ?? false) ? 'none' : 'hard')),
                'research_ok' => $researchUrl === '' || $this->draftQuality->researchGateAllowsDraft($researchUrl, $researchNotes),
                'grounded' => $quality['grounded'] ?? null,
                'not_generic' => $quality['not_generic'] ?? null,
                'has_clear_cta' => $quality['has_clear_cta'] ?? null,
                'summary' => $quality['summary'] ?? null,
                'issues' => $quality['issues'] ?? [],
                'evidence_used' => $quality['evidence_used'] ?? [],
                'warnings' => array_values(array_filter(array_merge(
                    $identityCheck['warnings'] ?? [],
                    $qualityAdvisory
                        ? ['Thin-context draft staged with an advisory quality warning — review before Launch.']
                        : [],
                ))),
            ],
        ];

        $plan = $this->audienceResolver->enrichPlanWithAudience($user, $plan);
        $plan = $this->funnel->attachToPlan($plan, $user);

        if (trim((string) ($plan['list_hash'] ?? '')) === '') {
            throw new \RuntimeException(
                'Could not attach that contact. Check the '.$label.' address/handle/URL and try again.'
            );
        }

        $live = $this->audienceResolver->liveLeadCount(
            $user,
            (string) ($plan['list_src'] ?? 'csv'),
            (string) $plan['list_hash'],
        );
        if ($live < 1) {
            throw new \RuntimeException('That contact list has no people yet.');
        }

        $plan = PlanLeadList::merge(
            $plan,
            (string) $plan['list_hash'],
            (string) ($plan['list_src'] ?? 'csv'),
            (string) ($plan['list_name'] ?? $recipient),
        );

        $setupOnly = $this->intent->wantsCampaignSetupOnly($message)
            || $this->intent->wantsDraftOnly($message);
        if ($setupOnly) {
            $plan['setup_only'] = true;
        }

        $approval = $this->approvals->createPending(
            $user,
            $organizationId,
            'draft_campaign_plan',
            AiToolPermission::Prepare,
            $plan,
            $conversation,
        );

        $autonomy = $this->settingsService->autonomy($settings);
        $card = $this->commandCenter->formatPlanCard($plan, $approval->id, $surface);

        $correctionLine = ($isCorrection && $recipientChanged)
            ? ($priorRecipient
                ? "Corrected recipient from **{$priorRecipient}** to **{$recipient}** — kept the researched draft."
                : "Corrected recipient to **{$recipient}** — kept the researched draft.")
            : null;
        $messageFixLine = $messageCorrection
            ? ($offerOverride !== ''
                ? 'Rewrote the draft to pitch: '.$offerOverride
                : 'Rewrote a fuller email using the site research.')
            : null;
        $researchLine = ($researchUrl !== '' && $this->composer->researchIsSubstantial($researchNotes))
            ? 'Researched → drafted → awaiting approval (nothing sent). Used '.$researchUrl.'.'
            : 'Drafted → awaiting approval (nothing sent).';
        $qualityLine = isset($quality['score'])
            ? 'Draft quality: '.round((float) $quality['score'] * 100).'%'.(
                ! empty($quality['summary']) ? ' — '.$quality['summary'] : ''
            ).($qualityAdvisory ? ' (advisory — staged for your review)' : '')
            : null;
        $warningLine = ! empty($plan['quality']['warnings'])
            ? implode("\n", $plan['quality']['warnings'])
            : (! empty($identityCheck['warnings'])
                ? implode("\n", $identityCheck['warnings'])
                : null);

        // Advisory thin-context drafts always need human Launch — never auto-send.
        if ($setupOnly || $qualityAdvisory || $autonomy->value <= AiAutonomyLevel::Copilot->value) {
            return [
                'handled' => true,
                'reply' => implode("\n\n", array_filter([
                    "**{$label} draft for {$recipient}** — ready for Review & Launch (nothing sent yet).",
                    $correctionLine,
                    $messageFixLine,
                    $researchLine,
                    $qualityLine,
                    $warningLine,
                    $draft !== '' ? "> {$draft}" : null,
                    $card,
                ])),
                'approval' => $approval,
            ];
        }

        if ($autonomy->value >= AiAutonomyLevel::Autopilot->value && ! $setupOnly) {
            $launch = $this->commandCenter->handleControlCommand(
                $user,
                $organizationId,
                'LAUNCH '.$approval->id,
            );

            return [
                'handled' => true,
                'reply' => implode("\n\n", array_filter([
                    $isCorrection
                        ? "Corrected and launched one-shot **{$label}** to **{$recipient}**."
                        : ($messageCorrection
                            ? "Rewrote and launched one-shot **{$label}** to **{$recipient}**."
                            : "Launched one-shot **{$label}** to **{$recipient}**."),
                    $correctionLine && ! $isCorrection ? $correctionLine : null,
                    $messageFixLine,
                    $researchLine,
                    $qualityLine,
                    $warningLine,
                    $draft !== '' ? "> {$draft}" : null,
                    (string) ($launch['reply'] ?? ''),
                ])),
                'approval' => $approval->fresh() ?? $approval,
            ];
        }

        return [
            'handled' => true,
            'reply' => implode("\n\n", array_filter([
                "**{$label} one-shot for {$recipient}** — staged for Review & Launch.",
                $correctionLine,
                $messageFixLine,
                $researchLine,
                $qualityLine,
                $warningLine,
                $draft !== '' ? "> {$draft}" : null,
                $card,
                'Tap **Launch** when you are ready — nothing sends until you approve.',
            ])),
            'approval' => $approval,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function priorColdOutboundPlan(AiConversation $conversation): ?array
    {
        $approval = AiActionApproval::query()
            ->where('conversation_id', $conversation->id)
            ->where('tool', 'draft_campaign_plan')
            ->where(function ($q) {
                $q->where('payload->source', 'cold_outbound')
                    ->orWhere('payload->one_shot', true);
            })
            ->orderByDesc('id')
            ->first();

        if (! $approval) {
            return null;
        }

        $payload = is_array($approval->payload) ? $approval->payload : [];

        return $payload !== [] ? $payload : null;
    }

    /**
     * @param  array<string, mixed>  $prior
     */
    private function priorRecipientLabel(array $prior): ?string
    {
        $label = trim((string) (
            $prior['contact_email']
            ?? $prior['audience']
            ?? $prior['linkedin_url']
            ?? $prior['instagram_handle']
            ?? $prior['telegram_handle']
            ?? $prior['twitter_handle']
            ?? $prior['contact_phone']
            ?? ''
        ));

        return $label !== '' ? $label : null;
    }

    /**
     * @param  array<string, mixed>  $identity
     */
    private function retargetDraft(string $priorDraft, array $identity): string
    {
        $draft = trim($priorDraft);
        if ($draft === '') {
            return '';
        }

        // Keep researched body; only strip a previous wrong-email signature line if present.
        return $draft;
    }

    /**
     * @return list<array{role:string,content:string}>
     */
    private function recentThread(AiConversation $conversation): array
    {
        return AiMessage::query()
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('id')
            ->limit(8)
            ->get(['role', 'content'])
            ->reverse()
            ->map(fn (AiMessage $message) => [
                'role' => (string) $message->role,
                'content' => Str::limit(trim((string) $message->content), 400, ''),
            ])
            ->filter(fn (array $row) => $row['content'] !== '')
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     channel: ?string,
     *     email: ?string,
     *     phone: ?string,
     *     linkedin_url: ?string,
     *     instagram_handle: ?string,
     *     telegram_handle: ?string,
     *     twitter_handle: ?string,
     *     research_url: ?string,
     *     display_name: ?string
     * }
     */
    private function identityFromPriorPlan(AiConversation $conversation): array
    {
        $empty = [
            'channel' => null,
            'email' => null,
            'phone' => null,
            'linkedin_url' => null,
            'instagram_handle' => null,
            'telegram_handle' => null,
            'twitter_handle' => null,
            'research_url' => null,
            'display_name' => null,
        ];

        $prior = $this->priorColdOutboundPlan($conversation);
        if (! is_array($prior)) {
            return $empty;
        }

        $channel = Str::lower(trim((string) ($prior['primary_channel'] ?? '')));
        if ($channel === '') {
            return $empty;
        }

        return [
            'channel' => $channel,
            'email' => isset($prior['contact_email']) ? Str::lower(trim((string) $prior['contact_email'])) : null,
            'phone' => isset($prior['contact_phone']) ? trim((string) $prior['contact_phone']) : null,
            'linkedin_url' => isset($prior['linkedin_url']) ? trim((string) $prior['linkedin_url']) : null,
            'instagram_handle' => isset($prior['instagram_handle']) ? Str::lower(trim((string) $prior['instagram_handle'])) : null,
            'telegram_handle' => isset($prior['telegram_handle']) ? Str::lower(trim((string) $prior['telegram_handle'])) : null,
            'twitter_handle' => isset($prior['twitter_handle']) ? Str::lower(trim((string) $prior['twitter_handle'])) : null,
            'research_url' => isset($prior['research_url']) ? trim((string) $prior['research_url']) : null,
            'display_name' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $identity
     */
    private function recipientLabel(array $identity): string
    {
        return (string) (
            $identity['display_name']
            ?? $identity['email']
            ?? $identity['linkedin_url']
            ?? (isset($identity['instagram_handle']) ? '@'.$identity['instagram_handle'] : null)
            ?? (isset($identity['telegram_handle']) ? '@'.$identity['telegram_handle'] : null)
            ?? (isset($identity['twitter_handle']) ? '@'.$identity['twitter_handle'] : null)
            ?? $identity['phone']
            ?? 'contact'
        );
    }

    private function label(string $channel): string
    {
        return OutreachChannelRegistry::channelLabel($channel);
    }
}

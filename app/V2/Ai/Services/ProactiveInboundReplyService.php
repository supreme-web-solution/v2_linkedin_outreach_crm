<?php

namespace App\V2\Ai\Services;

use App\Models\AiActionApproval;
use App\Models\AiConversation;
use App\Models\User;
use App\Models\V2Conversation;
use App\Models\V2Message;
use App\Models\V2OutreachLead;
use App\V2\Ai\Enums\AiAutonomyLevel;
use App\V2\Services\OpenAIContentService;
use App\V2\Services\UnifiedInboxReplyService;
use Illuminate\Support\Facades\Log;

class ProactiveInboundReplyService
{
    public function __construct(
        private readonly AiEmployeeSettingsService $settingsService,
        private readonly InboxClassificationService $classifier,
        private readonly InboxCommandCenterService $inboxCommandCenter,
        private readonly InboxConversationNotifier $notifier,
        private readonly InboxSociHandlingService $handling,
        private readonly UnifiedInboxReplyService $inboxReplies,
        private readonly OpenAIContentService $openai,
        private readonly ConversionNextActionService $conversionNextAction,
        private readonly BookMeetingCommandCenterService $bookMeeting,
    ) {}

    public function handle(int $v2ConversationId, int $userId, int $inboundMessageId): void
    {
        if (! (bool) config('socifusion_ai.proactive_inbound_reply', true)) {
            return;
        }

        $user = User::query()->find($userId);
        $conversation = V2Conversation::query()->find($v2ConversationId);

        if (! $user || ! $conversation || (int) $conversation->user_id !== $userId) {
            return;
        }

        if ($this->handling->isAlreadyHandled($conversation, $inboundMessageId)) {
            return;
        }

        $organizationId = (int) ($user->current_organization_id ?? 0);
        if ($organizationId <= 0) {
            return;
        }

        $settings = $this->settingsService->for($user, $organizationId);
        if ($this->settingsService->isBlocked($settings)) {
            return;
        }

        $inbound = V2Message::query()
            ->where('conversation_id', $conversation->id)
            ->whereKey($inboundMessageId)
            ->where('direction', 'inbound')
            ->first();

        if (! $inbound) {
            return;
        }

        $body = trim((string) ($inbound->body ?? ''));
        if ($body === '') {
            return;
        }

        $classification = $this->classifier->classify($body);
        if (($classification['intent'] ?? '') === 'opt_out') {
            return;
        }

        $researchRan = $this->inboundHadResearch($conversation);
        $lead = $this->leadFor($conversation);
        $next = $this->conversionNextAction->decide($user, $organizationId, $body, $lead, $classification);

        if (($next['action'] ?? '') === ConversionNextActionService::ACTION_OPT_OUT) {
            $this->handling->markHandled($conversation->fresh() ?? $conversation, $inboundMessageId, 'opt_out');

            return;
        }

        $commandCenter = app(CommandCenterService::class)->conversation($user, $organizationId);
        $autonomy = $this->settingsService->autonomy($settings);

        if (
            ($next['action'] ?? '') === ConversionNextActionService::ACTION_BOOK_MEETING
            && $autonomy->value >= \App\V2\Ai\Enums\AiAutonomyLevel::Assisted->value
        ) {
            $booked = $this->stageBooking(
                $user,
                $organizationId,
                $commandCenter,
                $conversation,
                $inboundMessageId,
                $classification,
                $researchRan,
                $autonomy,
            );
            if ($booked) {
                return;
            }
        }

        if ($this->providersUnavailable()) {
            $this->notifier->notify(
                $user,
                $organizationId,
                implode("\n\n", array_filter([
                    '📬 New **'.str_replace('_', ' ', (string) ($classification['priority'] ?? 'inbox')).'** reply needs you.',
                    '> '.mb_substr($body, 0, 200),
                    '[Open inbox]('.url('/inbox/'.$conversation->provider.'/'.$conversation->id).')',
                ])),
                ['v2_conversation_id' => $conversation->id, 'inbound_message_id' => $inboundMessageId],
            );
            $this->handling->markHandled($conversation->fresh() ?? $conversation, $inboundMessageId, 'notified_only');

            return;
        }

        try {
            $draftData = $this->inboxReplies->draftReplyForConversation($user, $conversation);
        } catch (\Throwable $e) {
            Log::warning('[Soci] Proactive inbound draft failed', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            $this->notifier->notify(
                $user,
                $organizationId,
                implode("\n\n", array_filter([
                    '📬 Reply from **'.($this->prospectName($conversation)).'** — draft failed, please reply in inbox.',
                    '> '.mb_substr($body, 0, 200),
                    '[Open inbox]('.url('/inbox/'.$conversation->provider.'/'.$conversation->id).')',
                ])),
                ['v2_conversation_id' => $conversation->id],
            );
            $this->handling->markHandled($conversation->fresh() ?? $conversation, $inboundMessageId, 'notify_failed_draft');

            return;
        }

        $approvalId = null;
        $autoSent = false;
        $mode = 'draft_notified';

        if ($autonomy->value >= \App\V2\Ai\Enums\AiAutonomyLevel::Assisted->value) {
            $staged = $this->inboxCommandCenter->stageDraftReply(
                $user,
                $organizationId,
                $commandCenter,
                $conversation->id,
                null,
                'web',
            );

            if ($staged['blocked'] ?? false) {
                return;
            }

            $autoSent = (bool) ($staged['auto_sent'] ?? false);
            $approvalId = $autoSent
                ? null
                : (isset($staged['approval_id']) ? (int) $staged['approval_id'] : null);
            $mode = $autoSent ? 'auto_sent' : 'draft_staged';
            $draftData['draft'] = (string) (($staged['plan']['draft_text'] ?? '') ?: $draftData['draft']);
        }

        $dossierSummary = null;
        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $leadId = (int) ($meta['outreach_lead_id'] ?? 0);
        if ($leadId > 0 && ($lead = V2OutreachLead::query()->find($leadId))) {
            $dossier = app(ProspectMemoryService::class)->dossier($lead);
            $dossierSummary = $this->summarizeDossier($dossier);
        }

        if ($this->shouldInstantNotify($classification, $autoSent, $approvalId)) {
            $this->notifier->notifyInboundReplyPrepared(
                $user,
                $organizationId,
                $conversation,
                $draftData,
                $classification,
                $approvalId,
                $autoSent,
                $researchRan,
                $dossierSummary,
            );
        }

        $this->mirrorToCommandCenterMemory(
            $commandCenter,
            $conversation,
            $body,
            $draftData,
            $classification,
            $approvalId,
            $autoSent,
        );

        $this->handling->markHandled(
            $conversation->fresh() ?? $conversation,
            $inboundMessageId,
            $mode,
            array_filter([
                'approval_id' => $approvalId,
                'classification' => $classification['priority'] ?? null,
            ]),
        );
    }

    /**
     * Keep Soci's Command Center transcript continuous with proactive inbox work.
     *
     * @param  array<string, mixed>  $draftData
     * @param  array<string, mixed>  $classification
     */
    private function mirrorToCommandCenterMemory(
        AiConversation $commandCenter,
        V2Conversation $inbox,
        string $inboundBody,
        array $draftData,
        array $classification,
        ?int $approvalId,
        bool $autoSent,
    ): void {
        $prospect = (string) ($draftData['prospect_name'] ?? 'Prospect');
        $intent = (string) ($classification['intent'] ?? 'neutral');
        $draft = trim((string) ($draftData['draft'] ?? ''));
        $status = $autoSent
            ? 'auto-sent'
            : ($approvalId ? 'staged for Review & Launch (LAUNCH '.$approvalId.')' : 'drafted for review');

        $content = implode("\n", array_filter([
            "Proactive inbox: {$prospect} replied ({$intent}) on {$inbox->provider}.",
            '> '.mb_substr($inboundBody, 0, 180),
            $draft !== '' ? "Draft ({$status}):\n".$draft : null,
        ]));

        try {
            \App\Models\AiMessage::query()->create([
                'conversation_id' => $commandCenter->id,
                'role' => 'assistant',
                'content' => $content,
                'meta' => [
                    'source' => 'proactive_inbound',
                    'v2_conversation_id' => $inbox->id,
                    'approval_id' => $approvalId,
                    'auto_sent' => $autoSent,
                ],
            ]);

            app(WorkstreamMemoryService::class)->rememberFacts($commandCenter, [
                'goal' => "Handle inbox reply from {$prospect}",
                'last_channel' => (string) $inbox->provider,
                'last_recipient' => $prospect,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Soci] Failed to mirror proactive inbound into Command Center memory', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function providersUnavailable(): bool
    {
        return app(AiProviderChain::class)->forAgent() === []
            && ! $this->openai->isConfigured();
    }

    /**
     * @param  array<string, mixed>  $classification
     */
    private function stageBooking(
        User $user,
        int $organizationId,
        AiConversation $commandCenter,
        V2Conversation $conversation,
        int $inboundMessageId,
        array $classification,
        bool $researchRan,
        AiAutonomyLevel $autonomy,
    ): bool {
        try {
            $staged = $this->bookMeeting->stage(
                $user,
                $organizationId,
                $commandCenter,
                $conversation->id,
                null,
                'web',
            );
        } catch (\Throwable $e) {
            Log::warning('[Soci] Proactive book_meeting failed', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if ($staged['blocked'] ?? false) {
            return false;
        }

        $autoSent = false;
        $approvalId = isset($staged['approval_id']) ? (int) $staged['approval_id'] : null;
        $mode = 'book_meeting_staged';

        if ($autonomy->value >= AiAutonomyLevel::Autopilot->value && $approvalId) {
            try {
                $approval = AiActionApproval::query()->find($approvalId);
                if ($approval) {
                    app(BookMeetingFromPlanService::class)->applyFromApproval($approval, $user);
                    $autoSent = true;
                    $approvalId = null;
                    $mode = 'book_meeting_sent';
                }
            } catch (\Throwable $e) {
                Log::warning('[Soci] Proactive book_meeting send failed', [
                    'conversation_id' => $conversation->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $draftData = [
            'draft' => (string) ($staged['plan']['draft_text'] ?? ''),
            'prospect_name' => $this->prospectName($conversation),
            'channel' => (string) $conversation->provider,
            'inbox_url' => url('/inbox/'.$conversation->provider.'/'.$conversation->id),
        ];

        $dossierSummary = null;
        if ($lead = $this->leadFor($conversation)) {
            $dossier = app(ProspectMemoryService::class)->dossier($lead);
            $dossierSummary = $this->summarizeDossier($dossier);
        }

        if ($this->shouldInstantNotify($classification, $autoSent, $approvalId)) {
            $this->notifier->notifyInboundReplyPrepared(
                $user,
                $organizationId,
                $conversation,
                $draftData,
                $classification,
                $approvalId,
                $autoSent,
                $researchRan,
                $dossierSummary,
            );
        }

        $this->handling->markHandled(
            $conversation->fresh() ?? $conversation,
            $inboundMessageId,
            $mode,
            array_filter([
                'approval_id' => $approvalId,
                'classification' => $classification['priority'] ?? null,
                'conversion_action' => ConversionNextActionService::ACTION_BOOK_MEETING,
            ]),
        );

        return true;
    }

    private function leadFor(V2Conversation $conversation): ?V2OutreachLead
    {
        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $leadId = (int) ($meta['outreach_lead_id'] ?? 0);
        if ($leadId <= 0) {
            return null;
        }

        return V2OutreachLead::query()->find($leadId);
    }

    private function inboundHadResearch(V2Conversation $conversation): bool
    {
        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $leadId = (int) ($meta['outreach_lead_id'] ?? 0);
        if ($leadId <= 0) {
            return false;
        }

        $lead = V2OutreachLead::query()->find($leadId);
        if (! $lead) {
            return false;
        }

        $dossier = app(ProspectMemoryService::class)->dossier($lead);
        $pages = is_array($dossier['scraped_pages'] ?? null) ? $dossier['scraped_pages'] : [];
        $research = is_array($dossier['company_research'] ?? null) ? $dossier['company_research'] : [];
        $facts = is_array($dossier['conversation_facts'] ?? null) ? $dossier['conversation_facts'] : [];

        return $pages !== [] || $research !== [] || count($facts) > 0;
    }

    /**
     * @param  array<string, mixed>  $classification
     */
    private function shouldInstantNotify(array $classification, bool $autoSent, ?int $approvalId): bool
    {
        if (! (bool) config('socifusion_ai.instant_inbound_notify.enabled', true)) {
            return false;
        }

        if ($autoSent && (bool) config('socifusion_ai.instant_inbound_notify.skip_when_ai_handled', true)) {
            return false;
        }

        if ($approvalId !== null && $approvalId > 0) {
            return true;
        }

        if ((bool) config('socifusion_ai.instant_inbound_notify.hot_only', true)) {
            return ($classification['priority'] ?? '') === 'hot';
        }

        return true;
    }

    private function prospectName(V2Conversation $conversation): string
    {
        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $name = trim((string) ($meta['prospect_name'] ?? ''));

        return $name !== '' ? $name : 'Prospect';
    }

    /**
     * @param  array<string, mixed>  $dossier
     * @return array<string, mixed>|null
     */
    private function summarizeDossier(array $dossier): ?array
    {
        $pages = collect($dossier['scraped_pages'] ?? [])
            ->filter(fn ($row) => is_array($row) && trim((string) ($row['url'] ?? '')) !== '')
            ->values()
            ->all();

        if ($pages === [] && trim((string) ($dossier['conversion_stage'] ?? '')) === '') {
            return null;
        }

        return [
            'conversion_stage' => (string) ($dossier['conversion_stage'] ?? 'opening'),
            'scraped_pages' => array_slice($pages, -3),
        ];
    }
}

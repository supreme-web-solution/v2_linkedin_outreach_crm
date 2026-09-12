<?php

namespace App\V2\Ai\Services;

use App\Models\User;
use App\Models\V2Conversation;
use App\Models\V2Message;
use App\Models\V2OutreachLead;
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

        if (! $this->openai->isConfigured()) {
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

        $commandCenter = app(CommandCenterService::class)->conversation($user, $organizationId);
        $autonomy = $this->settingsService->autonomy($settings);
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

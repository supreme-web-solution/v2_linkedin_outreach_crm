<?php

namespace App\V2\Services;

use App\Models\User;
use App\Models\V2Conversation;
use App\Models\V2Message;
use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLead;
use App\Models\V2OutreachLeadProgress;
use App\V2\Ai\Services\ConversionStageService;
use App\V2\Ai\Services\ProspectIntelligenceService;
use App\V2\Ai\Services\ProspectMemoryService;
use App\V2\Ai\Services\WorkspaceContextService;
use App\V2\Outreach\OutreachActivityLogger;
use App\V2\Outreach\OutreachChannelRegistry;
use App\V2\Outreach\OutreachWebhookProgressService;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class UnifiedInboxReplyService
{
    /** Recent messages sent in full to the AI — same for every inbox channel. */
    private const RECENT_MESSAGES_FOR_AI = 5;

    /** @var array<int, string> */
    public const INBOX_CHANNELS = [];

    /**
     * @return array<int, string>
     */
    public static function inboxChannels(): array
    {
        return OutreachChannelRegistry::inboxPlatforms();
    }

    public function __construct(
        private readonly UnifiedInboxService $inbox,
        private readonly OutreachChannelInboxSettingsService $channelSettings,
        private readonly AutoResponseService $autoResponses,
        private readonly OpenAIContentService $openai,
        private readonly OutreachActivityLogger $logger,
        private readonly ProspectIntelligenceService $prospectIntelligence,
        private readonly ProspectMemoryService $prospectMemory,
        private readonly ConversionStageService $conversionStages,
        private readonly WorkspaceContextService $workspaceContext,
    ) {}

    /**
     * Draft a reply for Command Center review (does not send).
     *
     * @return array{
     *     draft: string,
     *     prospect_name: string,
     *     channel: string,
     *     channel_label: string,
     *     inbound_preview: string,
     *     inbox_url: string,
     *     conversation_id: int
     * }
     */
    public function draftReplyForConversation(User $user, V2Conversation $conversation): array
    {
        if ((int) $conversation->user_id !== (int) $user->id) {
            throw new \RuntimeException('Conversation not found.');
        }

        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $leadId = (int) (Arr::get($meta, 'outreach_lead_id') ?? 0);
        $campaignId = (int) (Arr::get($meta, 'outreach_campaign_id') ?? 0);

        $lead = $leadId > 0 ? V2OutreachLead::query()->find($leadId) : null;
        $campaign = $campaignId > 0 ? V2OutreachCampaign::query()->find($campaignId) : null;

        $latestInbound = V2Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'inbound')
            ->orderByDesc('received_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        $inboundBody = trim((string) ($latestInbound?->body ?? ''));
        if ($inboundBody === '') {
            throw new \RuntimeException('No inbound message to reply to in this conversation.');
        }

        $aiContext = $this->channelSettings->aiContextFor($campaign, (string) $conversation->provider);
        if ($aiContext === '' && $campaign) {
            $aiContext = trim((string) ($campaign->name ?? '')).' outreach follow-up.';
        }

        if (! $this->openai->isConfigured()) {
            throw new \RuntimeException('OpenAI is not configured for inbox reply drafting.');
        }

        $draft = $this->generateAiReply($conversation, $inboundBody, $aiContext, $user, $lead, $campaign);
        if ($draft === '') {
            throw new \RuntimeException('Could not generate a reply draft. Check campaign AI context in outreach settings.');
        }

        $prospectName = trim((string) ($lead?->full_name ?? Arr::get($meta, 'prospect_name', 'Prospect')));

        return [
            'draft' => $draft,
            'prospect_name' => $prospectName !== '' ? $prospectName : 'Prospect',
            'channel' => (string) $conversation->provider,
            'channel_label' => OutreachChannelRegistry::channelLabel((string) $conversation->provider),
            'inbound_preview' => Str::limit($inboundBody, 200, '…'),
            'inbox_url' => url('/inbox/'.$conversation->provider.'/'.$conversation->id),
            'conversation_id' => $conversation->id,
        ];
    }

    public function sendApprovedReply(User $user, V2Conversation $conversation, string $text): V2Message
    {
        if ((int) $conversation->user_id !== (int) $user->id) {
            throw new \RuntimeException('Conversation not found.');
        }

        $body = trim($text);
        if ($body === '') {
            throw new \RuntimeException('Reply text is empty.');
        }

        $body = \App\V2\Ai\Support\RecipientFacingCopyGuard::prepareOutbound($body, [
            'user' => $user,
            'organization_id' => (int) ($user->current_organization_id ?? $conversation->organization_id ?? 0),
        ]);
        \App\V2\Ai\Support\RecipientFacingCopyGuard::assertSendable($body);

        $message = $this->inbox->sendMessage($user, $conversation, $body);
        $this->recordOutboundConversionStage($user, $conversation, $body);

        return $message;
    }

    public function handleInbound(V2Conversation $conversation, string $inboundBody, int $userId): void
    {
        $inboundBody = trim($inboundBody);
        if ($inboundBody === '') {
            return;
        }

        $user = User::query()->find($userId);
        if (! $user) {
            return;
        }

        $organizationId = (int) ($user->current_organization_id ?? 0);
        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $leadId = (int) (Arr::get($meta, 'outreach_lead_id') ?? 0);
        $campaignId = (int) (Arr::get($meta, 'outreach_campaign_id') ?? 0);

        $lead = $leadId > 0 ? V2OutreachLead::query()->find($leadId) : null;
        $campaign = $campaignId > 0 ? V2OutreachCampaign::query()->find($campaignId) : null;

        if ($lead) {
            try {
                $this->prospectIntelligence->processInbound($lead, $conversation, $inboundBody);
                $lead = $lead->fresh() ?? $lead;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        if ($lead && $campaign) {
            $channel = OutreachChannelRegistry::normalizeChannelKey((string) $conversation->provider);
            $webhookProgress = app(OutreachWebhookProgressService::class);
            $webhookProgress->recordInboundReply(
                $lead,
                $campaign,
                $channel,
                $inboundBody,
            );
            // Keep original pause-on-reply everywhere, except while sitting on a reply condition
            // (Has replied? / Message replied? / No reply?). Those need the inbound flag first,
            // then the Yes/No branch — pause-on-reply must not hard-stop them mid-evaluation.
            // Invite accepted / email opened / bounce waits still pause immediately on reply.
            if ($webhookProgress->isWaitingOnReplyCondition($lead->fresh() ?? $lead, $campaign)) {
                $this->markPendingPauseOnReply($lead, $campaign, $channel);
            } else {
                $this->pauseOutreachOnReply($conversation, $lead, $campaign, $inboundBody);
            }
        }

        if ($organizationId <= 0) {
            return;
        }

        if ($this->autoResponses->handleInbound($conversation, $inboundBody, $userId, $organizationId)) {
            return;
        }

        if (! $this->channelSettings->autoReplyEnabled($campaign, (string) $conversation->provider)) {
            return;
        }

        $aiContext = $this->channelSettings->aiContextFor($campaign, (string) $conversation->provider);
        if ($aiContext === '' || ! $this->openai->isConfigured()) {
            return;
        }

        $reply = $this->generateAiReply($conversation, $inboundBody, $aiContext, $user, $lead, $campaign);
        if ($reply === '') {
            return;
        }

        try {
            $this->inbox->sendMessage($user, $conversation, $reply);
            $this->recordOutboundConversionStage($user, $conversation, $reply);
        } catch (\Throwable) {
            return;
        }
    }

    private function recordOutboundConversionStage(User $user, V2Conversation $conversation, string $body): void
    {
        $orgId = (int) ($user->current_organization_id ?? 0);
        if ($orgId <= 0) {
            return;
        }

        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $leadId = (int) (Arr::get($meta, 'outreach_lead_id') ?? 0);
        if ($leadId <= 0) {
            return;
        }

        $lead = V2OutreachLead::query()->find($leadId);
        if (! $lead) {
            return;
        }

        try {
            $this->conversionStages->recordOutbound($lead, $body, $user, $orgId);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function markPendingPauseOnReply(
        V2OutreachLead $lead,
        V2OutreachCampaign $campaign,
        string $channel,
    ): void {
        if (! $this->channelSettings->pauseOnReply($campaign, $channel)) {
            return;
        }

        $progress = V2OutreachLeadProgress::query()->firstOrCreate(
            ['outreach_campaign_id' => $campaign->id, 'outreach_lead_id' => $lead->id],
            ['current_node_key' => 0, 'next_node_key' => 1, 'run_status' => 0, 'channel_state' => []]
        );

        $progressMeta = is_array($progress->meta) ? $progress->meta : [];
        $progressMeta['pending_pause_on_reply'] = true;
        $progressMeta['paused_channel'] = $channel;
        $progress->forceFill(['meta' => $progressMeta])->save();
    }

    private function pauseOutreachOnReply(
        V2Conversation $conversation,
        V2OutreachLead $lead,
        V2OutreachCampaign $campaign,
        string $inboundBody,
    ): void {
        $provider = OutreachChannelRegistry::normalizeChannelKey((string) $conversation->provider);
        if (! $this->channelSettings->pauseOnReply($campaign, $provider)) {
            return;
        }

        if (in_array($lead->status, ['done', 'skipped', 'replied'], true)) {
            return;
        }

        $progress = V2OutreachLeadProgress::query()->firstOrCreate(
            ['outreach_campaign_id' => $campaign->id, 'outreach_lead_id' => $lead->id],
            ['current_node_key' => 0, 'next_node_key' => 1, 'run_status' => 0, 'channel_state' => []]
        );

        $progressMeta = is_array($progress->meta) ? $progress->meta : [];
        $progressMeta['paused_reason'] = 'inbound_reply';
        $progressMeta['paused_at'] = now()->toIso8601String();
        $progressMeta['paused_channel'] = $provider;
        unset($progressMeta['pending_pause_on_reply']);

        $progress->forceFill([
            'next_run_at' => null,
            'meta' => $progressMeta,
        ])->save();

        $lead->forceFill(['status' => 'replied'])->save();

        $this->logger->log(
            $campaign->id,
            $lead->id,
            null,
            null,
            'paused',
            sprintf(
                '%s replied on %s — outreach paused.',
                $lead->full_name ?? 'Lead',
                OutreachChannelRegistry::channelLabel($provider),
            ),
            [
                'channel' => $provider,
                'conversation_id' => $conversation->id,
            ],
        );
    }

    private function generateAiReply(
        V2Conversation $conversation,
        string $inboundBody,
        string $aiContext,
        User $user,
        ?V2OutreachLead $lead,
        ?V2OutreachCampaign $campaign,
    ): string {
        $leadName = trim((string) ($lead?->full_name ?? Arr::get($conversation->meta ?? [], 'prospect_name', '')));
        if ($leadName === '') {
            $leadName = 'there';
        }

        $context = $this->buildAiConversationContext($conversation, $leadName);

        try {
            $orgId = (int) ($user->current_organization_id ?? 0);
            $agentNotes = [];

            if ($orgId > 0) {
                $businessBrief = $this->workspaceContext->inboxBusinessBrief($user, $orgId);
                if ($businessBrief !== '') {
                    $agentNotes[] = $businessBrief;
                }

                $conversionGuide = $this->workspaceContext->inboxConversionGuide($user, $orgId);
                if ($conversionGuide !== '') {
                    $agentNotes[] = $conversionGuide;
                }
            }

            if ($lead) {
                $stageGuide = $orgId > 0
                    ? $this->conversionStages->replyGuide($lead, $user, $orgId)
                    : '';
                if ($stageGuide !== '') {
                    $agentNotes[] = $stageGuide;
                }

                $dossierBrief = $this->prospectMemory->agentBrief($lead);
                if ($dossierBrief !== '') {
                    $agentNotes[] = $dossierBrief;
                }
            }

            return $this->openai->generateInboxReply(
                (string) $conversation->provider,
                $aiContext,
                $context['recent'],
                $inboundBody,
                $leadName,
                [
                    'campaign_name' => (string) ($campaign?->name ?? ''),
                    'lead_headline' => trim((string) ($lead?->headline ?? Arr::get($conversation->meta ?? [], 'prospect_headline', ''))) ?: null,
                    'thread_summary' => $context['summary'],
                    'sender_name' => \App\V2\Ai\Support\SenderIdentity::displayName(
                        $user,
                        $orgId,
                    ),
                    'agent_notes' => trim(implode("\n\n", array_filter($agentNotes))),
                ],
            );
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Last few messages in full, plus a cached summary of everything before that.
     *
     * @return array{recent: array<int, array{role: string, body: string, source?: string|null}>, summary: string}
     */
    private function buildAiConversationContext(V2Conversation $conversation, string $leadName): array
    {
        $all = V2Message::query()
            ->where('conversation_id', $conversation->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->map(fn (V2Message $message) => [
                'id' => $message->id,
                'role' => $message->direction === 'outbound' ? 'assistant' : 'user',
                'body' => (string) ($message->body ?? ''),
                'source' => Arr::get($message->meta ?? [], 'source'),
            ])
            ->filter(fn (array $row) => trim($row['body']) !== '')
            ->values()
            ->all();

        if ($all === []) {
            return ['recent' => [], 'summary' => ''];
        }

        $recent = array_map(
            fn (array $row) => Arr::except($row, ['id']),
            array_slice($all, -self::RECENT_MESSAGES_FOR_AI),
        );

        if (count($all) <= self::RECENT_MESSAGES_FOR_AI) {
            return ['recent' => $recent, 'summary' => ''];
        }

        $older = array_slice($all, 0, -self::RECENT_MESSAGES_FOR_AI);
        $lastOlderId = (int) ($older[array_key_last($older)]['id'] ?? 0);
        $summary = $this->resolveThreadSummary($conversation, $older, $lastOlderId, $leadName);

        return ['recent' => $recent, 'summary' => $summary];
    }

    /**
     * @param  array<int, array{id: int, role: string, body: string, source?: string|null}>  $olderMessages
     */
    private function resolveThreadSummary(
        V2Conversation $conversation,
        array $olderMessages,
        int $lastOlderId,
        string $leadName,
    ): string {
        if ($olderMessages === []) {
            return '';
        }

        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $cached = is_array($meta['ai_chat_summary'] ?? null) ? $meta['ai_chat_summary'] : [];
        $cachedThrough = (int) ($cached['through_message_id'] ?? 0);
        $cachedText = trim((string) ($cached['text'] ?? ''));

        if ($cachedText !== '' && $cachedThrough >= $lastOlderId) {
            return $cachedText;
        }

        $olderForSummary = array_map(fn (array $row) => Arr::except($row, ['id']), $olderMessages);
        $priorSummary = null;

        if ($cachedText !== '' && $cachedThrough > 0) {
            $newSinceCache = array_values(array_filter(
                $olderMessages,
                fn (array $row) => (int) ($row['id'] ?? 0) > $cachedThrough,
            ));

            if ($newSinceCache !== [] && count($newSinceCache) < count($olderMessages)) {
                $olderForSummary = array_map(fn (array $row) => Arr::except($row, ['id']), $newSinceCache);
                $priorSummary = $cachedText;
            }
        }

        try {
            $summary = $this->openai->summarizeInboxThread(
                (string) $conversation->provider,
                $olderForSummary,
                $leadName,
                $priorSummary,
            );
        } catch (\Throwable) {
            return $cachedText;
        }

        if ($summary === '') {
            return $cachedText;
        }

        $meta['ai_chat_summary'] = [
            'text' => $summary,
            'through_message_id' => $lastOlderId,
            'updated_at' => now()->toIso8601String(),
        ];

        $conversation->forceFill(['meta' => $meta])->save();

        return $summary;
    }
}

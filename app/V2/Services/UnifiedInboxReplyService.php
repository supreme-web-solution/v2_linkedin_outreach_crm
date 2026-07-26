<?php

namespace App\V2\Services;

use App\Models\User;
use App\Models\V2Conversation;
use App\Models\V2Message;
use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLead;
use App\Models\V2OutreachLeadProgress;
use App\V2\Outreach\OutreachActivityLogger;
use App\V2\Outreach\OutreachChannelRegistry;
use App\V2\Outreach\OutreachWebhookProgressService;
use Illuminate\Support\Arr;

class UnifiedInboxReplyService
{
    /** Recent messages sent in full to the AI — same for every inbox channel. */
    private const RECENT_MESSAGES_FOR_AI = 5;

    /** @var array<int, string> */
    public const INBOX_CHANNELS = [
        'linkedin',
        'whatsapp',
        'instagram',
        'telegram',
        'twitter',
        'email',
    ];

    public function __construct(
        private readonly UnifiedInboxService $inbox,
        private readonly OutreachChannelInboxSettingsService $channelSettings,
        private readonly AutoResponseService $autoResponses,
        private readonly OpenAIContentService $openai,
        private readonly OutreachActivityLogger $logger,
    ) {}

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

        if ($lead && $campaign) {
            app(OutreachWebhookProgressService::class)->recordInboundReply(
                $lead,
                $campaign,
                (string) $conversation->provider,
                $inboundBody,
            );
            $this->pauseOutreachOnReply($conversation, $lead, $campaign, $inboundBody);
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
        } catch (\Throwable) {
            return;
        }
    }

    private function pauseOutreachOnReply(
        V2Conversation $conversation,
        V2OutreachLead $lead,
        V2OutreachCampaign $campaign,
        string $inboundBody,
    ): void {
        $provider = (string) $conversation->provider;
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

        $provider = (string) $conversation->provider;
        $progressMeta = is_array($progress->meta) ? $progress->meta : [];
        $progressMeta['paused_reason'] = 'inbound_reply';
        $progressMeta['paused_at'] = now()->toIso8601String();
        $progressMeta['paused_channel'] = $provider;

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

<?php

namespace App\V2\Outreach;

use App\Models\V2Conversation;
use App\Models\V2IntegrationAccount;
use App\Models\V2Message;
use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLead;
use App\Models\V2OutreachLeadProgress;
use App\V2\Integrations\Unipile\UnipileProvider;
use App\V2\Services\UnifiedInboxService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Live Unipile / inbox checks while waiting on reply or invite conditions.
 */
class OutreachReplyProbeService
{
    public function __construct(
        private readonly UnifiedInboxService $inbox,
        private readonly OutreachWebhookProgressService $webhookProgress,
    ) {}

    /**
     * @param  array<string, mixed>  $node
     */
    public function probe(
        V2OutreachCampaign $campaign,
        V2OutreachLead $lead,
        V2OutreachLeadProgress $progress,
        array $node,
    ): bool {
        $condition = (string) ($node['condition'] ?? '');
        $channel = OutreachChannelRegistry::normalizeChannelKey((string) ($node['channel'] ?? 'linkedin'));

        if ($condition === 'invite_accepted' && $channel === 'linkedin') {
            return $this->probeLinkedInInviteAccepted($campaign, $lead);
        }

        if (! OutreachConditionEvaluator::isReplyCondition($condition)
            && $condition !== 'email_opened'
            && $condition !== 'email_bounced') {
            return false;
        }

        if (in_array($condition, ['email_opened', 'email_bounced', 'email_replied'], true)
            || $channel === 'email') {
            return $this->probeEmail($campaign, $lead, $progress, $condition);
        }

        return $this->probeMessagingChannel($campaign, $lead, $progress, $channel);
    }

    private function probeLinkedInInviteAccepted(
        V2OutreachCampaign $campaign,
        V2OutreachLead $lead,
    ): bool {
        $identifier = trim((string) ($lead->provider_profile_id ?? ''));
        $profileUrl = trim((string) ($lead->profile_url ?? ''));
        if ($identifier === '' && $profileUrl !== '' && preg_match('~linkedin\.com/in/([^/?#]+)~i', $profileUrl, $m)) {
            $identifier = $m[1];
        }
        if ($identifier === '') {
            return false;
        }

        $accountId = V2IntegrationAccount::activeUnipileAccountId((int) $campaign->user_id);
        if (! $accountId) {
            return false;
        }

        try {
            $provider = app(UnipileProvider::class);
            $profile = str_contains($profileUrl, 'linkedin.com/in/')
                ? $provider->getProfileByUrl($profileUrl, $accountId)
                : $provider->getProfileByIdentifier($identifier, ['account_id' => $accountId]);
        } catch (Throwable $e) {
            Log::warning('[Outreach] Invite-accepted Unipile probe failed', [
                'lead_id' => $lead->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $distance = Arr::get($profile, 'network_distance')
            ?? Arr::get($profile, 'provider_data.network_distance')
            ?? Arr::get($profile, 'distance');
        $normalized = strtolower(trim((string) $distance));
        $connected = in_array($normalized, ['1', '1st', 'first', 'distance_1', 'dist_1', 'first_degree'], true)
            || str_contains($normalized, 'distance_1')
            || str_contains($normalized, 'first_degree')
            || Arr::get($profile, 'is_relationship') === true
            || Arr::get($profile, 'connected') === true;

        if (! $connected) {
            return false;
        }

        $this->webhookProgress->markLinkedInInviteAccepted($lead, advanceCondition: false);

        return true;
    }

    private function probeEmail(
        V2OutreachCampaign $campaign,
        V2OutreachLead $lead,
        V2OutreachLeadProgress $progress,
        string $condition,
    ): bool {
        try {
            $this->inbox->syncEmailInboxForUser((int) $campaign->user_id);
        } catch (Throwable $e) {
            Log::debug('[Outreach] Email inbox sync failed during probe', [
                'lead_id' => $lead->id,
                'error' => $e->getMessage(),
            ]);
        }

        $progress->refresh();
        $channelState = is_array($progress->channel_state) ? $progress->channel_state : [];
        $emailState = is_array($channelState['email'] ?? null) ? $channelState['email'] : [];

        if ($condition === 'email_opened' && ! empty($emailState['opened'])) {
            return true;
        }
        if ($condition === 'email_bounced' && ! empty($emailState['bounced'])) {
            return true;
        }
        if (in_array($condition, ['email_replied', 'has_replied', 'message_replied', 'no_reply'], true)
            && ! empty($emailState['replied'])) {
            return true;
        }

        $conversation = $this->findLeadConversation((int) $campaign->user_id, $lead->id, 'email');
        if ($conversation && $this->conversationHasInbound($conversation)) {
            $this->webhookProgress->recordInboundReply(
                $lead,
                $campaign,
                'email',
                $this->latestInboundPreview($conversation),
                advanceCondition: false,
            );

            return true;
        }

        return false;
    }

    private function probeMessagingChannel(
        V2OutreachCampaign $campaign,
        V2OutreachLead $lead,
        V2OutreachLeadProgress $progress,
        string $channel,
    ): bool {
        $conversation = $this->findLeadConversation((int) $campaign->user_id, $lead->id, $channel);
        if (! $conversation) {
            return false;
        }

        try {
            // Quiet sync — avoid nested reply handlers while ProcessOutreachLeadJob evaluates.
            $this->inbox->syncMessagesFromProvider($conversation->fresh() ?? $conversation, triggerReplyHandlers: false);
        } catch (Throwable $e) {
            Log::debug('[Outreach] Message sync failed during reply probe', [
                'lead_id' => $lead->id,
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);
        }

        $progress->refresh();
        $channelState = is_array($progress->channel_state) ? $progress->channel_state : [];
        $state = is_array($channelState[$channel] ?? null) ? $channelState[$channel] : [];
        if (! empty($state['replied'])) {
            return true;
        }

        $fresh = $conversation->fresh() ?? $conversation;
        if ($this->conversationHasInbound($fresh)) {
            $this->webhookProgress->recordInboundReply(
                $lead,
                $campaign,
                $channel,
                $this->latestInboundPreview($fresh),
                advanceCondition: false,
            );

            return true;
        }

        return $this->probeUnipileChatDirect($campaign, $lead, $fresh, $channel);
    }

    private function probeUnipileChatDirect(
        V2OutreachCampaign $campaign,
        V2OutreachLead $lead,
        V2Conversation $conversation,
        string $channel,
    ): bool {
        $chatId = trim((string) ($conversation->provider_chat_id ?? ''));
        if ($chatId === '') {
            return false;
        }

        $accountId = V2IntegrationAccount::activeUnipileAccountIdForProvider(
            (int) $campaign->user_id,
            $channel,
        );
        if (! $accountId) {
            return false;
        }

        try {
            $response = app(UnipileProvider::class)->listMessages(
                $chatId,
                ['limit' => 30],
                ['account_id' => $accountId],
            );
        } catch (Throwable $e) {
            Log::debug('[Outreach] Unipile listMessages probe failed', [
                'lead_id' => $lead->id,
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $items = Arr::get($response, 'items');
        if (! is_array($items) || $items === []) {
            $items = Arr::get($response, 'data.items', []);
        }
        if (! is_array($items)) {
            return false;
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $isSender = Arr::get($item, 'is_sender');
            $direction = strtolower((string) (Arr::get($item, 'direction') ?? ''));
            $inbound = $isSender === false
                || $isSender === 0
                || $isSender === 'false'
                || in_array($direction, ['inbound', 'incoming', 'received'], true);

            if (! $inbound) {
                continue;
            }

            $body = trim((string) (
                Arr::get($item, 'text')
                ?? Arr::get($item, 'body')
                ?? Arr::get($item, 'message')
                ?? ''
            ));

            $this->webhookProgress->recordInboundReply(
                $lead,
                $campaign,
                $channel,
                $body !== '' ? $body : '[Inbound message]',
                advanceCondition: false,
            );

            return true;
        }

        return false;
    }

    private function findLeadConversation(int $userId, int $leadId, string $channel): ?V2Conversation
    {
        return V2Conversation::query()
            ->where('user_id', $userId)
            ->where('provider', $channel)
            ->where('meta->outreach_lead_id', $leadId)
            ->orderByDesc('id')
            ->first();
    }

    private function conversationHasInbound(V2Conversation $conversation): bool
    {
        return V2Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'inbound')
            ->exists();
    }

    private function latestInboundPreview(V2Conversation $conversation): string
    {
        $body = (string) (V2Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'inbound')
            ->latest('id')
            ->value('body') ?? '');

        return mb_substr(trim($body), 0, 200);
    }
}

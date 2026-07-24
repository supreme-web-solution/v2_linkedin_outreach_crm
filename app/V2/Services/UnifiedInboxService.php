<?php

namespace App\V2\Services;

use App\Jobs\V2\ProcessOutboundOutreachJob;
use App\Models\User;
use App\Models\V2Conversation;
use App\Models\V2IntegrationAccount;
use App\Models\V2Message;
use App\Models\V2OutreachLead;
use App\V2\Integrations\Unipile\UnipileProvider;
use App\V2\Outreach\OutreachChannelRegistry;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class UnifiedInboxService
{
    /**
     * Resolve or create a multi-channel inbox thread from a Unipile webhook.
     *
     * @param  array<string, mixed>  $payload
     */
    public function resolveConversationForWebhook(int $userId, string $chatId, array $payload = []): ?V2Conversation
    {
        if ($chatId === '') {
            return null;
        }

        $provider = $this->resolveProviderFromPayload($payload);
        if ($provider === null) {
            return null;
        }

        $existing = V2Conversation::query()
            ->where('user_id', $userId)
            ->where('provider', $provider)
            ->where('provider_chat_id', $chatId)
            ->forOutreachInbox()
            ->first();

        if ($existing) {
            return $this->enrichConversationMeta($existing, $payload);
        }

        $attendeeIds = $this->extractAttendeeIds($payload);
        $outreachLead = $this->matchOutreachLead($userId, $provider, $attendeeIds, $payload);
        $contactName = $this->extractContactName($payload, $outreachLead);

        return V2Conversation::query()->create([
            'user_id' => $userId,
            'provider' => $provider,
            'provider_chat_id' => $chatId,
            'status' => 'active',
            'last_message_at' => now(),
            'meta' => array_filter([
                'source' => 'unified_inbox',
                'prospect_name' => $contactName,
                'prospect_headline' => $outreachLead?->headline,
                'outreach_lead_id' => $outreachLead?->id,
                'outreach_campaign_id' => $outreachLead?->outreach_campaign_id,
                'attendee_ids' => $attendeeIds !== [] ? $attendeeIds : null,
                'channel_label' => OutreachChannelRegistry::channelLabel($provider),
            ], fn ($value) => $value !== null && $value !== ''),
        ]);
    }

    /**
     * Link an outbound multi-channel campaign message to the unified inbox.
     *
     * @param  array<string, mixed>  $response
     */
    public function recordOutboundChat(
        int $userId,
        int $organizationId,
        string $channel,
        V2OutreachLead $lead,
        string $recipientId,
        array $response,
        string $messageText,
    ): ?V2Conversation {
        $chatId = (string) (
            Arr::get($response, 'id')
            ?? Arr::get($response, 'chat_id')
            ?? Arr::get($response, 'data.id')
            ?? Arr::get($response, 'data.chat_id')
            ?? ''
        );

        if ($chatId === '') {
            return null;
        }

        $conversation = V2Conversation::query()->firstOrCreate(
            [
                'user_id' => $userId,
                'provider' => $channel,
                'provider_chat_id' => $chatId,
            ],
            [
                'status' => 'active',
                'last_message_at' => now(),
                'meta' => [
                    'source' => 'unified_inbox',
                    'organization_id' => $organizationId,
                    'prospect_name' => trim((string) ($lead->full_name ?? '')) ?: null,
                    'prospect_headline' => $lead->headline,
                    'outreach_lead_id' => $lead->id,
                    'outreach_campaign_id' => $lead->outreach_campaign_id,
                    'attendee_ids' => [$recipientId],
                    'channel_label' => OutreachChannelRegistry::channelLabel($channel),
                ],
            ]
        );

        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $conversation->forceFill([
            'last_message_at' => now(),
            'status' => 'active',
            'meta' => array_merge($meta, array_filter([
                'source' => 'unified_inbox',
                'organization_id' => $organizationId,
                'outreach_lead_id' => $lead->id,
                'outreach_campaign_id' => $lead->outreach_campaign_id,
                'prospect_name' => trim((string) ($lead->full_name ?? '')) ?: ($meta['prospect_name'] ?? null),
            ], fn ($value) => $value !== null && $value !== '')),
        ])->save();

        $body = trim($messageText);
        if ($body !== '') {
            V2Message::query()->firstOrCreate(
                [
                    'conversation_id' => $conversation->id,
                    'direction' => 'outbound',
                    'body' => $body,
                ],
                [
                    'sent_at' => now(),
                    'meta' => [
                        'source' => 'outreach_campaign',
                        'outreach_lead_id' => $lead->id,
                    ],
                ]
            );
        }

        return $conversation;
    }

    /**
     * Pull messages from Unipile when webhooks did not arrive (common on local dev).
     */
    public function syncMessagesFromProvider(V2Conversation $conversation): void
    {
        $chatId = trim((string) ($conversation->provider_chat_id ?? ''));
        if ($chatId === '') {
            return;
        }

        $provider = (string) $conversation->provider;
        if ($provider === '') {
            return;
        }

        $accountId = V2IntegrationAccount::activeUnipileAccountIdForProvider((int) $conversation->user_id, $provider);
        if (! $accountId) {
            return;
        }

        try {
            $response = app(UnipileProvider::class)->listMessages(
                $chatId,
                ['limit' => 50],
                ['account_id' => $accountId]
            );
        } catch (\Throwable) {
            return;
        }

        $items = Arr::get($response, 'items');
        if (! is_array($items) || $items === []) {
            $items = Arr::get($response, 'data.items', []);
        }

        if (! is_array($items) || $items === []) {
            return;
        }

        $latestAt = $conversation->last_message_at;
        $newInboundBodies = [];
        $since = ($conversation->created_at ?? now())->copy()->subMinute();

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $text = trim((string) ($item['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $at = ! empty($item['timestamp'])
                ? Carbon::parse((string) $item['timestamp'])
                : now();

            if ($at->lt($since)) {
                continue;
            }

            $providerMessageId = trim((string) ($item['id'] ?? $item['message_id'] ?? ''));
            $isOutbound = ($item['is_sender'] ?? 0) === 1
                || ($item['is_sender'] ?? false) === true
                || ($item['from_me'] ?? false) === true;
            $direction = $isOutbound ? 'outbound' : 'inbound';

            if ($providerMessageId !== '') {
                $existing = V2Message::query()
                    ->where('conversation_id', $conversation->id)
                    ->where('provider_message_id', $providerMessageId)
                    ->first();

                if ($existing) {
                    if (! $latestAt || $at->gt($latestAt)) {
                        $latestAt = $at;
                    }

                    continue;
                }
            }

            if ($direction === 'outbound' && $providerMessageId !== '') {
                $orphan = V2Message::query()
                    ->where('conversation_id', $conversation->id)
                    ->where('direction', 'outbound')
                    ->whereNull('provider_message_id')
                    ->where('body', $text)
                    ->first();

                if ($orphan) {
                    $orphan->forceFill([
                        'provider_message_id' => $providerMessageId,
                        'sent_at' => $orphan->sent_at ?? $at,
                    ])->save();

                    if (! $latestAt || $at->gt($latestAt)) {
                        $latestAt = $at;
                    }

                    continue;
                }
            }

            V2Message::query()->create([
                'conversation_id' => $conversation->id,
                'provider_message_id' => $providerMessageId !== '' ? $providerMessageId : null,
                'direction' => $direction,
                'body' => $text,
                'sent_at' => $direction === 'outbound' ? $at : null,
                'received_at' => $direction === 'inbound' ? $at : null,
                'meta' => [
                    'source' => 'unipile_sync',
                ],
            ]);

            if ($direction === 'inbound') {
                $newInboundBodies[] = $text;
            }

            if (! $latestAt || $at->gt($latestAt)) {
                $latestAt = $at;
            }
        }

        if ($latestAt) {
            $conversation->forceFill([
                'last_message_at' => $latestAt,
                'status' => 'active',
            ])->save();
        }

        if ($newInboundBodies !== [] && $conversation->isInboxThread()) {
            $replyService = app(UnifiedInboxReplyService::class);
            $fresh = $conversation->fresh();

            foreach ($newInboundBodies as $body) {
                if ($fresh) {
                    $replyService->handleInbound($fresh, $body, (int) $conversation->user_id);
                }
            }
        }
    }

    public function sendMessage(User $user, V2Conversation $conversation, string $text): V2Message
    {
        $text = trim($text);
        if ($text === '') {
            throw new \InvalidArgumentException('Message cannot be empty.');
        }

        if (!$conversation->provider_chat_id) {
            throw new \RuntimeException('Conversation is not linked to a Unipile chat.');
        }

        $provider = (string) $conversation->provider;
        $accountId = V2IntegrationAccount::activeUnipileAccountIdForProvider($user->id, $provider);
        if (!$accountId) {
            throw new \RuntimeException(
                'Connect '.OutreachChannelRegistry::channelLabel($provider).' via Integrations first.'
            );
        }

        $organizationId = (int) ($user->current_organization_id ?? 0);
        if ($organizationId <= 0) {
            throw new \RuntimeException('No workspace selected.');
        }

        $message = app(OutreachPersistenceService::class)->createOutboundMessage(
            $conversation->id,
            $text,
            'message',
            [
                'chat_id' => $conversation->provider_chat_id,
                'source' => 'unified_inbox',
                '_unipile_account_id' => $accountId,
                'channel' => $provider,
            ]
        );

        ProcessOutboundOutreachJob::dispatch(
            'message',
            $user->id,
            $organizationId,
            $conversation->id,
            $message->id,
            [
                'chat_id' => $conversation->provider_chat_id,
                'text' => $text,
                '_unipile_account_id' => $accountId,
                'account_id' => $accountId,
            ]
        );

        $conversation->forceFill(['last_message_at' => now(), 'status' => 'active'])->save();

        return $message;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveProviderFromPayload(array $payload): ?string
    {
        $providerRaw = (string) (
            Arr::get($payload, 'data.account_type')
            ?? Arr::get($payload, 'account_type')
            ?? Arr::get($payload, 'data.provider')
            ?? Arr::get($payload, 'provider')
            ?? Arr::get($payload, 'data.account.provider')
            ?? Arr::get($payload, 'account.provider')
            ?? ''
        );

        if ($providerRaw !== '') {
            $mapped = OutreachChannelRegistry::integrationProviderForUnipileType($providerRaw)
                ?? strtolower($providerRaw);

            if ($mapped !== '' && $mapped !== 'linkedin') {
                return $mapped;
            }
        }

        $accountId = (string) (
            Arr::get($payload, 'data.account_id')
            ?? Arr::get($payload, 'account_id')
            ?? ''
        );

        if ($accountId !== '') {
            $account = V2IntegrationAccount::query()
                ->where('provider_account_id', $accountId)
                ->orWhere('meta->unipile_account_id', $accountId)
                ->orderByDesc('id')
                ->first();

            $provider = (string) ($account?->provider ?? '');
            if ($provider !== '' && $provider !== 'linkedin') {
                return $provider;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $attendeeIds
     */
    private function matchOutreachLead(int $userId, string $provider, array $attendeeIds, array $payload): ?V2OutreachLead
    {
        $query = V2OutreachLead::query()
            ->whereHas('campaign', fn ($q) => $q->where('user_id', $userId));

        foreach ($attendeeIds as $attendeeId) {
            $lead = $this->matchLeadByIdentifier($query->clone(), $provider, $attendeeId);
            if ($lead) {
                return $lead;
            }
        }

        $senderName = trim((string) (
            Arr::get($payload, 'data.sender.display_name')
            ?? Arr::get($payload, 'sender.display_name')
            ?? Arr::get($payload, 'data.sender.name')
            ?? Arr::get($payload, 'sender.name')
            ?? ''
        ));

        if ($senderName !== '') {
            return $query->clone()
                ->where('full_name', 'like', '%'.$senderName.'%')
                ->latest('id')
                ->first();
        }

        return null;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<V2OutreachLead>  $query
     */
    private function matchLeadByIdentifier($query, string $provider, string $identifier): ?V2OutreachLead
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        return match ($provider) {
            'whatsapp' => $query->clone()
                ->where(function ($q) use ($identifier) {
                    $q->where('phone', $identifier)
                        ->orWhere('meta->whatsapp_provider_id', $identifier);
                })
                ->latest('id')
                ->first(),
            'instagram' => $query->clone()
                ->where('meta->instagram_provider_id', $identifier)
                ->latest('id')
                ->first(),
            'telegram' => $query->clone()
                ->where('meta->telegram_provider_id', $identifier)
                ->latest('id')
                ->first(),
            'twitter' => $query->clone()
                ->where('meta->twitter_provider_id', $identifier)
                ->latest('id')
                ->first(),
            'email' => $query->clone()
                ->where('email', $identifier)
                ->latest('id')
                ->first(),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractContactName(array $payload, ?V2OutreachLead $lead): ?string
    {
        $fromLead = trim((string) ($lead?->full_name ?? ''));
        if ($fromLead !== '') {
            return $fromLead;
        }

        foreach ([
            Arr::get($payload, 'data.sender.display_name'),
            Arr::get($payload, 'sender.display_name'),
            Arr::get($payload, 'data.sender.name'),
            Arr::get($payload, 'sender.name'),
            Arr::get($payload, 'data.attendees.0.display_name'),
            Arr::get($payload, 'attendees.0.display_name'),
        ] as $name) {
            $value = trim((string) ($name ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    private function extractAttendeeIds(array $payload): array
    {
        $ids = [];

        foreach ([
            Arr::get($payload, 'data.attendees'),
            Arr::get($payload, 'attendees'),
        ] as $group) {
            if (!is_array($group)) {
                continue;
            }

            foreach ($group as $attendee) {
                if (is_string($attendee) && $attendee !== '') {
                    $ids[] = $attendee;
                    continue;
                }

                if (is_array($attendee)) {
                    $id = (string) (
                        Arr::get($attendee, 'provider_id')
                        ?? Arr::get($attendee, 'id')
                        ?? Arr::get($attendee, 'attendee_provider_id')
                        ?? ''
                    );
                    if ($id !== '') {
                        $ids[] = $id;
                    }
                }
            }
        }

        foreach ([
            Arr::get($payload, 'data.sender_id'),
            Arr::get($payload, 'sender_id'),
            Arr::get($payload, 'data.sender.provider_id'),
            Arr::get($payload, 'sender.provider_id'),
            Arr::get($payload, 'data.sender.attendee_provider_id'),
            Arr::get($payload, 'sender.attendee_provider_id'),
        ] as $single) {
            if (is_string($single) && $single !== '') {
                $ids[] = $single;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function enrichConversationMeta(V2Conversation $conversation, array $payload): V2Conversation
    {
        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $attendeeIds = $this->extractAttendeeIds($payload);
        $outreachLead = $this->matchOutreachLead(
            (int) $conversation->user_id,
            (string) $conversation->provider,
            $attendeeIds,
            $payload
        );

        $updates = array_filter([
            'source' => 'unified_inbox',
            'prospect_name' => $meta['prospect_name'] ?? $this->extractContactName($payload, $outreachLead),
            'outreach_lead_id' => $meta['outreach_lead_id'] ?? $outreachLead?->id,
            'outreach_campaign_id' => $meta['outreach_campaign_id'] ?? $outreachLead?->outreach_campaign_id,
            'attendee_ids' => $attendeeIds !== [] ? $attendeeIds : ($meta['attendee_ids'] ?? null),
        ], fn ($value) => $value !== null && $value !== '');

        if ($updates !== []) {
            $conversation->forceFill(['meta' => array_merge($meta, $updates)])->save();
        }

        return $conversation->fresh();
    }
}

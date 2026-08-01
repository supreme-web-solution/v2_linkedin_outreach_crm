<?php

namespace App\Jobs\V2;

use App\Models\V2Conversation;
use App\Models\V2IntegrationAccount;
use App\Models\V2Message;
use App\Models\V2ProviderEvent;
use App\Models\V2UserActivity;
use App\Models\V2Call;
use App\Models\User;
use App\Jobs\V2\HandleCallInboundReplyJob;
use App\V2\Integrations\Unipile\UnipileProvider;
use App\V2\Outreach\OutreachChannelGuard;
use App\V2\Outreach\OutreachChannelRegistry;
use App\V2\Services\AutoResponseService;
use App\V2\Services\CallCalendarService;
use App\V2\Services\CallOrchestrationService;
use App\V2\Services\OutreachPersistenceService;
use App\V2\Services\UnifiedInboxReplyService;
use App\V2\Services\UnifiedInboxService;
use App\V2\Services\UnipileMessageNormalizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;

class ProcessUnipileWebhookEventJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly int $providerEventId)
    {
        $this->onQueue('webhooks');
    }

    public function handle(OutreachPersistenceService $persistence, UnifiedInboxService $unifiedInbox, UnifiedInboxReplyService $inboxReply): void
    {
        $event = V2ProviderEvent::query()->find($this->providerEventId);
        if (!$event || $event->processed_at) {
            return;
        }

        $payload = is_array($event->payload) ? $event->payload : [];
        if ($this->shouldSkipDisabledChannel($payload)) {
            $event->forceFill(['processed_at' => now()])->save();

            return;
        }

        $eventType = $event->event_type;
        $knownEventTypes = [
            'message.received',
            'new_message',
            'message.sent',
            'outbound_message',
            'message.reaction',
            'invitation.accepted',
            'connection.accepted',
            'invitation.received',
            'invitation.sent',
            'invitation.rejected',
            'invitation.cancelled',
            'invitation.expired',
            'chat.read',
            'chat.read_state.changed',
            'chat.unread',
            'chat.reopened',
            'chat.archived',
            'chat.closed',
            'profile.viewed',
            'profile.visited',
            'relation.followed',
            'relation.unfollowed',
            'post.reaction',
            'post.commented',
            'post.engagement',
            'post.shared',
            'account.disconnected',
            'account.reconnected',
            'account.connected',
            'account.error',
            'account.creation.success',
            'account.add',
            'account.status.running',
            'email.opened',
            'email.replied',
            'email.bounced',
            'mail.opened',
            'mail.received',
            'mail.bounced',
            'email.tracked.open',
            'email.failed',
            'event.created',
            'event.updated',
            'calendar.event.created',
            'calendar.event.updated',
        ];

        if (in_array($eventType, ['message.received', 'new_message'], true)) {
            $chatId = (string) (Arr::get($payload, 'data.chat_id') ?? Arr::get($payload, 'chat_id') ?? '');
            $body = $this->extractMessageBody($payload);
            $providerMessageId = (string) (Arr::get($payload, 'data.message_id') ?? Arr::get($payload, 'message_id') ?? '');
            $normalizer = app(UnipileMessageNormalizer::class);
            $inner = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
            $attachments = $normalizer->extractAttachments($inner);
            $reactions = $normalizer->extractReactions($inner);

            if ($chatId !== '' && $event->user_id && ($body !== '' || $attachments !== [])) {
                $reactionEvent = $normalizer->resolveReactionEvent(array_merge($inner, ['text' => $body]));
                if ($reactionEvent !== null) {
                    $conversation = $persistence->resolveConversationForWebhook(
                        (int) $event->user_id,
                        $chatId,
                        $payload
                    ) ?? $unifiedInbox->resolveConversationForWebhook(
                        (int) $event->user_id,
                        $chatId,
                        $payload
                    );

                    if ($conversation && empty($reactionEvent['skip_only'])) {
                        $targetId = trim((string) ($reactionEvent['target_provider_message_id'] ?? ''));
                        if ($targetId !== '') {
                            $unifiedInbox->applyParsedReaction($conversation, $reactionEvent);
                        }
                    }

                    $this->recordWebhookActivity(
                        (int) $event->user_id,
                        'webhook.message.received',
                        1,
                        ['event_id' => $event->event_id, 'chat_id' => $chatId, 'reaction' => true]
                    );
                } elseif (app(UnipileProvider::class)->isFromAccountOwner($payload)) {
                    $this->persistOutboundMessage($event, $payload, $chatId, $body, $providerMessageId, $persistence, $unifiedInbox);
                    $this->recordWebhookActivity(
                        (int) $event->user_id,
                        'webhook.message.sent',
                        1,
                        ['event_id' => $event->event_id, 'chat_id' => $chatId]
                    );
                } else {
                    $conversation = $persistence->resolveConversationForWebhook(
                        (int) $event->user_id,
                        $chatId,
                        $payload
                    );

                    if (!$conversation) {
                        $conversation = $unifiedInbox->resolveConversationForWebhook(
                            (int) $event->user_id,
                            $chatId,
                            $payload
                        );
                    }

                if ($conversation) {
                    $messageAt = $this->extractMessageTimestamp($payload);
                    V2Message::query()->updateOrCreate(
                        [
                            'conversation_id' => $conversation->id,
                            'provider_message_id' => $providerMessageId ?: null,
                        ],
                        [
                            'direction' => 'inbound',
                            'body' => $body !== '' ? $body : '[Attachment]',
                            'attachments' => $attachments !== [] ? $attachments : null,
                            'received_at' => $messageAt,
                            'meta' => array_filter([
                                'source' => 'unipile_webhook',
                                'event_id' => $event->event_id,
                                'reactions' => $reactions !== [] ? $reactions : null,
                            ], fn ($v) => $v !== null),
                        ]
                    );

                    $conversation->forceFill([
                        'last_message_at' => $messageAt,
                    ])->save();

                    if ($conversation->isInboxThread()) {
                        app(UnifiedInboxReplyService::class)->handleInbound(
                            $conversation,
                            $body,
                            (int) $event->user_id
                        );
                    } else {
                    $call = V2Call::query()
                        ->where('conversation_id', $conversation->id)
                        ->whereNotIn('status', ['completed', 'lost', 'failed'])
                        ->latest('id')
                        ->first();

                    if (!$call) {
                        $call = $this->linkOrphanCallToConversation(
                            (int) $event->user_id,
                            $conversation,
                            $payload
                        );
                    }

                    if ($call && $body !== '') {
                        HandleCallInboundReplyJob::dispatch($call->id, $body);
                    } elseif ($body !== '') {
                        $user = User::query()->find($event->user_id);
                        $orgId = (int) ($user?->current_organization_id ?? 0);
                        if ($user && $orgId > 0) {
                            app(AutoResponseService::class)->handleInbound($conversation, $body, $user->id, $orgId);
                        }
                    }
                    }
                }

                $this->recordWebhookActivity(
                    (int) $event->user_id,
                    'webhook.message.received',
                    1,
                    [
                        'event_id' => $event->event_id,
                        'chat_id' => $chatId,
                    ]
                );
                }
            }
        }

        if ($eventType === 'message.reaction' && $event->user_id) {
            $chatId = (string) (Arr::get($payload, 'data.chat_id') ?? Arr::get($payload, 'chat_id') ?? '');
            $reaction = app(UnipileMessageNormalizer::class)->extractReactionEvent($payload);

            if ($chatId !== '' && $reaction) {
                $conversation = $unifiedInbox->resolveConversationForWebhook((int) $event->user_id, $chatId, $payload)
                    ?? $persistence->resolveConversationForWebhook((int) $event->user_id, $chatId, $payload);

                if ($conversation) {
                    $unifiedInbox->applyReactionToMessage($conversation, $reaction['message_id'], [
                        'value' => $reaction['value'],
                        'sender_id' => $reaction['sender_id'],
                        'is_sender' => $reaction['is_sender'],
                    ]);
                }
            }
        }

        if (in_array($eventType, ['message.sent', 'outbound_message'], true) && $event->user_id) {
            $chatId = (string) (Arr::get($payload, 'data.chat_id') ?? Arr::get($payload, 'chat_id') ?? '');
            $body = $this->extractMessageBody($payload);
            $providerMessageId = (string) (Arr::get($payload, 'data.message_id') ?? Arr::get($payload, 'message_id') ?? '');

            if ($chatId !== '' && trim($body) !== '') {
                $this->persistOutboundMessage($event, $payload, $chatId, $body, $providerMessageId, $persistence, $unifiedInbox);
            }

            if ($chatId !== '' && $event->user_id) {
                app(\App\V2\Outreach\OutreachWebhookProgressService::class)
                    ->confirmOutboundSendFromWebhook((int) $event->user_id, $chatId, $providerMessageId !== '' ? $providerMessageId : null);
            }

            $this->recordWebhookActivity(
                (int) $event->user_id,
                'webhook.message.sent',
                1,
                ['event_id' => $event->event_id, 'chat_id' => $chatId]
            );
        }

        if (in_array($eventType, ['invitation.accepted', 'connection.accepted'], true) && $event->user_id) {
            app(\App\V2\Outreach\OutreachWebhookProgressService::class)
                ->handleInvitationAccepted((int) $event->user_id, $payload);

            $this->recordWebhookActivity(
                (int) $event->user_id,
                'webhook.connection.accepted',
                1,
                ['event_id' => $event->event_id]
            );
        }

        if (in_array($eventType, ['invitation.received', 'invitation.sent', 'invitation.rejected', 'invitation.cancelled', 'invitation.expired'], true) && $event->user_id) {
            $this->recordWebhookActivity(
                (int) $event->user_id,
                'webhook.'.str_replace('.', '_', $eventType),
                1,
                ['event_id' => $event->event_id]
            );
        }

        if (in_array($eventType, ['chat.read', 'chat.read_state.changed'], true) && $event->user_id) {
            $chatId = (string) (Arr::get($payload, 'data.chat_id') ?? Arr::get($payload, 'chat_id') ?? '');
            if ($chatId !== '') {
                V2Conversation::query()
                    ->where('user_id', $event->user_id)
                    ->where('provider_chat_id', $chatId)
                    ->update([
                        'status' => 'read',
                    ]);
            }

            $this->recordWebhookActivity(
                (int) $event->user_id,
                'webhook.chat.read',
                1,
                [
                    'event_id' => $event->event_id,
                    'chat_id' => $chatId,
                ]
            );
        }

        if (in_array($eventType, ['chat.archived', 'chat.closed'], true) && $event->user_id) {
            $chatId = (string) (Arr::get($payload, 'data.chat_id') ?? Arr::get($payload, 'chat_id') ?? '');
            if ($chatId !== '') {
                V2Conversation::query()
                    ->where('user_id', $event->user_id)
                    ->where('provider_chat_id', $chatId)
                    ->update([
                        'status' => 'archived',
                    ]);
            }

            $this->recordWebhookActivity(
                (int) $event->user_id,
                'webhook.chat.archived',
                1,
                ['event_id' => $event->event_id, 'chat_id' => $chatId]
            );
        }

        if (in_array($eventType, ['chat.unread', 'chat.reopened'], true) && $event->user_id) {
            $chatId = (string) (Arr::get($payload, 'data.chat_id') ?? Arr::get($payload, 'chat_id') ?? '');
            if ($chatId !== '') {
                V2Conversation::query()
                    ->where('user_id', $event->user_id)
                    ->where('provider_chat_id', $chatId)
                    ->update([
                        'status' => 'active',
                    ]);
            }

            $this->recordWebhookActivity(
                (int) $event->user_id,
                'webhook.chat.reopened',
                1,
                ['event_id' => $event->event_id, 'chat_id' => $chatId, 'event_type' => $eventType]
            );
        }

        if (in_array($eventType, ['profile.viewed', 'profile.visited', 'relation.followed', 'relation.unfollowed'], true) && $event->user_id) {
            $this->recordWebhookActivity(
                (int) $event->user_id,
                'webhook.profile.activity',
                1,
                [
                    'event_id' => $event->event_id,
                    'profile_id' => (string) (Arr::get($payload, 'data.profile_id') ?? Arr::get($payload, 'profile_id') ?? ''),
                    'event_type' => $eventType,
                ]
            );
        }

        if (in_array($eventType, ['post.reaction', 'post.commented', 'post.engagement', 'post.shared'], true) && $event->user_id) {
            $this->recordWebhookActivity(
                (int) $event->user_id,
                'webhook.post.engagement',
                1,
                [
                    'event_id' => $event->event_id,
                    'post_id' => (string) (Arr::get($payload, 'data.post_id') ?? Arr::get($payload, 'post_id') ?? ''),
                    'event_type' => $eventType,
                ]
            );
        }

        if (in_array($eventType, ['email.opened', 'mail.opened', 'email.tracked.open', 'email.replied', 'mail.received', 'email.bounced', 'mail.bounced', 'email.failed'], true) && $event->user_id) {
            app(\App\V2\Outreach\OutreachWebhookProgressService::class)
                ->handleEmailTrackingEvent((int) $event->user_id, $eventType, $payload);

            if (in_array($eventType, ['email.replied', 'mail.received'], true)) {
                $unifiedInbox->handleInboundEmailWebhook((int) $event->user_id, $payload);
            }

            $this->recordWebhookActivity(
                (int) $event->user_id,
                'webhook.email.tracking',
                1,
                ['event_id' => $event->event_id, 'event_type' => $eventType]
            );
        }

        if (in_array($eventType, ['account.disconnected', 'account.reconnected', 'account.connected', 'account.error', 'account.creation.success', 'account.add', 'account.status.running'], true) && $event->user_id) {
            $statusMap = [
                'account.reconnected' => 'reconnected',
                'account.disconnected' => 'disconnected',
                'account.connected' => 'connected',
                'account.error' => 'error',
            ];
            $accountId = (string) (Arr::get($payload, 'data.account_id') ?? Arr::get($payload, 'account_id') ?? '');
            $providerRaw = (string) (Arr::get($payload, 'data.provider') ?? Arr::get($payload, 'provider') ?? 'LINKEDIN');
            $provider = OutreachChannelRegistry::integrationProviderForUnipileType($providerRaw)
                ?? strtolower($providerRaw);
            $normalizedStatus = in_array($eventType, [
                'account.connected',
                'account.reconnected',
                'account.creation.success',
                'account.add',
                'account.status.running',
            ], true) ? 'active' : ($statusMap[$eventType] ?? 'unknown');

            if ($accountId !== '') {
                $organizationId = (int) (User::query()->where('id', $event->user_id)->value('current_organization_id') ?? 0);
                $existing = V2IntegrationAccount::query()
                    ->where('user_id', $event->user_id)
                    ->where('provider', $provider)
                    ->first();

                $existingMeta = is_array($existing?->meta) ? $existing->meta : [];
                $meta = array_merge($existingMeta, [
                    'organization_id' => $organizationId > 0 ? $organizationId : ($existingMeta['organization_id'] ?? null),
                    'unipile_account_id' => $accountId,
                    'unipile_type' => strtoupper($providerRaw),
                    'connection_method' => $existingMeta['connection_method'] ?? 'hosted',
                    'connected_at' => $existingMeta['connected_at'] ?? now()->toIso8601String(),
                    'last_event_type' => $eventType,
                    'last_event_id' => $event->event_id,
                    'last_event_at' => now()->toIso8601String(),
                ]);

                V2IntegrationAccount::query()->updateOrCreate(
                    [
                        'user_id' => $event->user_id,
                        'provider' => $provider,
                    ],
                    [
                        'provider_account_id' => $accountId,
                        'status' => $normalizedStatus,
                        'meta' => $meta,
                        'last_synced_at' => now(),
                    ]
                );

                if ($eventType === 'account.disconnected') {
                    $channelKey = OutreachChannelRegistry::channelKeyForUnipileType($providerRaw) ?? $provider;
                    $organizationId = (int) (User::query()->where('id', $event->user_id)->value('current_organization_id') ?? 0);
                    app(OutreachChannelGuard::class)->handleChannelDisconnect(
                        (int) $event->user_id,
                        $organizationId > 0 ? $organizationId : null,
                        $channelKey,
                        'Account disconnected via webhook',
                    );
                }
            }

            $this->recordWebhookActivity(
                (int) $event->user_id,
                'webhook.account.'.($statusMap[$eventType] ?? 'status_changed'),
                1,
                [
                    'event_id' => $event->event_id,
                    'account_id' => $accountId,
                ]
            );
        }

        if (in_array($eventType, ['event.created', 'calendar.event.created'], true) && $event->user_id) {
            $handled = app(CallCalendarService::class)->handleInboundCalendarEvent((int) $event->user_id, $payload);
            $this->recordWebhookActivity(
                (int) $event->user_id,
                $handled ? 'webhook.calendar.event_booked' : 'webhook.calendar.event_ignored',
                1,
                [
                    'event_id' => $event->event_id,
                    'event_type' => $eventType,
                ]
            );
        }

        if ($event->user_id && !in_array($eventType, $knownEventTypes, true)) {
            $this->recordWebhookActivity(
                (int) $event->user_id,
                'webhook.unmapped_event',
                1,
                [
                    'event_id' => $event->event_id,
                    'event_type' => $eventType,
                ]
            );
        }

        $event->forceFill(['processed_at' => now()])->save();
    }

    public function failed(\Throwable $exception): void
    {
        app(\App\V2\Services\OpsAlertService::class)->notify(
            'unipile_webhook',
            'Unipile webhook job failed after retries',
            [
                'provider_event_id' => $this->providerEventId,
                'error' => $exception->getMessage(),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function linkOrphanCallToConversation(int $userId, V2Conversation $conversation, array $payload): ?V2Call
    {
        $attendeeIds = $this->extractAttendeeIds($payload, $conversation);

        foreach ($attendeeIds as $attendeeId) {
            $call = V2Call::query()
                ->where('user_id', $userId)
                ->whereNull('conversation_id')
                ->where('connection_id', $attendeeId)
                ->whereNotIn('status', ['completed', 'lost', 'failed'])
                ->latest('id')
                ->first();

            if ($call) {
                return app(CallOrchestrationService::class)->linkConversation($call, $conversation->id, User::query()->findOrFail($userId));
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function persistOutboundMessage(
        V2ProviderEvent $event,
        array $payload,
        string $chatId,
        string $body,
        string $providerMessageId,
        OutreachPersistenceService $persistence,
        UnifiedInboxService $unifiedInbox,
    ): void {
        if (!$event->user_id) {
            return;
        }

        $conversation = $persistence->resolveConversationForWebhook(
            (int) $event->user_id,
            $chatId,
            $payload
        );

        if (!$conversation) {
            $conversation = $unifiedInbox->resolveConversationForWebhook(
                (int) $event->user_id,
                $chatId,
                $payload
            );
        }

        if (!$conversation) {
            return;
        }

        $messageAt = $this->extractMessageTimestamp($payload);

        $existing = null;
        if ($providerMessageId !== '') {
            $existing = V2Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('provider_message_id', $providerMessageId)
                ->first();
        }

        if (!$existing) {
            $existing = V2Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('direction', 'outbound')
                ->where('body', $body)
                ->whereNull('provider_message_id')
                ->latest('id')
                ->first();
        }

        if ($existing) {
            $meta = is_array($existing->meta) ? $existing->meta : [];
            $existing->forceFill([
                'direction' => 'outbound',
                'body' => $body,
                'provider_message_id' => $providerMessageId !== '' ? $providerMessageId : $existing->provider_message_id,
                'sent_at' => $messageAt,
                'meta' => $meta + [
                    'source' => 'unipile_webhook',
                    'event_id' => $event->event_id,
                ],
            ])->save();
        } else {
            V2Message::query()->create([
                'conversation_id' => $conversation->id,
                'provider_message_id' => $providerMessageId !== '' ? $providerMessageId : null,
                'direction' => 'outbound',
                'body' => $body,
                'sent_at' => $messageAt,
                'meta' => [
                    'source' => 'unipile_webhook',
                    'event_id' => $event->event_id,
                ],
            ]);
        }

        $conversation->forceFill([
            'last_message_at' => $messageAt,
            'status' => 'active',
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractAttendeeIds(array $payload, V2Conversation $conversation): array
    {
        $ids = [];

        foreach ([
            Arr::get($payload, 'data.attendees'),
            Arr::get($payload, 'attendees'),
            Arr::get($conversation->meta ?? [], 'attendee_ids'),
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
                    $id = (string) (Arr::get($attendee, 'provider_id')
                        ?? Arr::get($attendee, 'id')
                        ?? Arr::get($attendee, 'attendee_provider_id')
                        ?? '');
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
    private function extractMessageBody(array $payload): string
    {
        return trim((string) (
            Arr::get($payload, 'data.text')
            ?? Arr::get($payload, 'text')
            ?? Arr::get($payload, 'data.message')
            ?? Arr::get($payload, 'message')
            ?? Arr::get($payload, 'data.body')
            ?? Arr::get($payload, 'body')
            ?? ''
        ));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function shouldSkipDisabledChannel(array $payload): bool
    {
        $providerRaw = (string) (Arr::get($payload, 'data.provider') ?? Arr::get($payload, 'provider') ?? Arr::get($payload, 'data.account_type') ?? Arr::get($payload, 'account_type') ?? '');
        if ($providerRaw === '') {
            return false;
        }

        $channelKey = OutreachChannelRegistry::channelKeyForUnipileType($providerRaw);
        if ($channelKey === null) {
            return false;
        }

        return ! OutreachChannelRegistry::isEnabled($channelKey);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractMessageTimestamp(array $payload): \Illuminate\Support\Carbon
    {
        $raw = Arr::get($payload, 'timestamp')
            ?? Arr::get($payload, 'data.timestamp')
            ?? Arr::get($payload, 'data.date')
            ?? Arr::get($payload, 'date');

        if ($raw !== null && $raw !== '') {
            try {
                return \Illuminate\Support\Carbon::parse((string) $raw)->toMutable();
            } catch (\Throwable) {
            }
        }

        return \Illuminate\Support\Carbon::now();
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function recordWebhookActivity(int $userId, string $identifier, int $stat, array $meta = []): void
    {
        $organizationId = User::query()->where('id', $userId)->value('current_organization_id');
        if (!$organizationId) {
            return;
        }

        V2UserActivity::query()->create([
            'user_id' => $userId,
            'organization_id' => (int) $organizationId,
            'module' => 'webhook',
            'stat' => $stat,
            'identifier' => $identifier,
            'meta' => $meta,
        ]);
    }
}

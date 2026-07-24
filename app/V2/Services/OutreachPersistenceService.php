<?php

namespace App\V2\Services;

use App\Jobs\V2\ProcessOutboundOutreachJob;
use App\Models\V2Conversation;
use App\Models\V2Lead;
use App\Models\V2Message;
use App\Models\V2ProviderEvent;
use App\Models\V2OrganizationUser;
use App\Models\V2IntegrationAccount;
use App\V2\Integrations\ProviderManager;
use Illuminate\Support\Arr;

class OutreachPersistenceService
{
    public function __construct(
        private readonly ProviderManager $providerManager,
    ) {
    }

    public function findOrCreateLead(int $userId, ?string $recipientId, array $profile = []): ?V2Lead
    {
        if (!$recipientId) {
            return null;
        }

        $providerProfileId = (string) (
            Arr::get($profile, 'provider_id')
            ?? Arr::get($profile, 'id')
            ?? $recipientId
        );
        $publicIdentifier = trim((string) (Arr::get($profile, 'public_identifier') ?? ''));

        return V2Lead::query()->updateOrCreate(
            [
                'user_id' => $userId,
                'provider' => 'linkedin',
                'provider_profile_id' => $providerProfileId,
            ],
            array_filter([
                'public_identifier' => $publicIdentifier !== '' ? $publicIdentifier : $recipientId,
                'full_name' => Arr::get($profile, 'full_name'),
                'headline' => Arr::get($profile, 'headline'),
                'company_name' => Arr::get($profile, 'company_name'),
                'location' => Arr::get($profile, 'location'),
            ], fn ($value) => $value !== null && $value !== '')
        );
    }

    /**
     * Resolve a LinkedIn vanity slug or URL to a Unipile provider_id.
     *
     * @return array{provider_id: string|null, profile: array<string, mixed>}
     */
    public function resolveRecipientId(int $userId, int $organizationId, string $recipientId): array
    {
        $recipientId = trim($recipientId);
        if ($recipientId === '') {
            return ['provider_id' => null, 'profile' => []];
        }

        if (preg_match('~linkedin\.com/in/([^/?#]+)~i', $recipientId, $matches)) {
            $recipientId = $matches[1];
        }

        $accountId = V2IntegrationAccount::activeUnipileAccountId($userId);
        $context = array_filter([
            'account_id' => $accountId,
            'owner_id' => (string) $userId,
            'organization_id' => $organizationId,
        ]);

        $providerKey = $this->providerManager->defaultProvider();
        $profileProvider = $this->providerManager->profile($providerKey);

        if (!method_exists($profileProvider, 'resolveProviderId')) {
            return ['provider_id' => $recipientId, 'profile' => []];
        }

        return $profileProvider->resolveProviderId($recipientId, $context);
    }

    public function findOrCreateConversation(
        int $userId,
        int $organizationId,
        ?int $leadId = null,
        ?string $providerChatId = null,
        array $meta = []
    ): V2Conversation {
        if ($providerChatId) {
            $conversation = V2Conversation::query()->firstOrCreate(
                [
                    'user_id' => $userId,
                    'provider' => 'linkedin',
                    'provider_chat_id' => $providerChatId,
                ],
                [
                    'lead_id' => $leadId,
                    'status' => 'active',
                    'meta' => ['organization_id' => $organizationId] + $meta,
                ]
            );

            if ($leadId && !$conversation->lead_id) {
                $conversation->forceFill(['lead_id' => $leadId])->save();
            }

            return $conversation;
        }

        $recipientId = $this->primaryRecipientId($meta);
        if (!$leadId && $recipientId) {
            $leadId = $this->findOrCreateLead($userId, $recipientId)?->id;
        }

        $existing = $this->findConversationForRecipient($userId, $leadId, $recipientId);
        if ($existing) {
            return $this->touchConversationMeta($existing, $organizationId, $meta, $leadId);
        }

        return V2Conversation::query()->create([
            'user_id' => $userId,
            'provider' => 'linkedin',
            'lead_id' => $leadId,
            'status' => 'active',
            'meta' => ['organization_id' => $organizationId] + $meta,
        ]);
    }

    public function findConversationForRecipient(
        int $userId,
        ?int $leadId = null,
        ?string $recipientId = null
    ): ?V2Conversation {
        if ($leadId) {
            $byLead = V2Conversation::query()
                ->where('user_id', $userId)
                ->where('provider', 'linkedin')
                ->where('lead_id', $leadId)
                ->orderByRaw('provider_chat_id IS NOT NULL DESC')
                ->orderByDesc('last_message_at')
                ->orderByDesc('id')
                ->first();

            if ($byLead) {
                return $byLead;
            }
        }

        if ($recipientId) {
            $lead = V2Lead::query()
                ->where('user_id', $userId)
                ->where('provider', 'linkedin')
                ->where('provider_profile_id', $recipientId)
                ->first();

            if ($lead) {
                return $this->findConversationForRecipient($userId, $lead->id, null);
            }

            return V2Conversation::query()
                ->where('user_id', $userId)
                ->where('provider', 'linkedin')
                ->where('meta', 'like', '%"'.$recipientId.'"%')
                ->orderByRaw('provider_chat_id IS NOT NULL DESC')
                ->orderByDesc('last_message_at')
                ->orderByDesc('id')
                ->first();
        }

        return null;
    }

    /**
     * Attach webhook chat id to an existing prospect thread when possible.
     *
     * @param  array<string, mixed>  $payload
     */
    public function resolveConversationForWebhook(int $userId, string $chatId, array $payload = []): ?V2Conversation
    {
        $existing = V2Conversation::query()
            ->where('user_id', $userId)
            ->where('provider', 'linkedin')
            ->where('provider_chat_id', $chatId)
            ->first();

        if ($existing) {
            return $existing;
        }

        $recipientIds = $this->extractRecipientIdsFromPayload($payload);
        foreach ($recipientIds as $recipientId) {
            $lead = $this->findOrCreateLead($userId, $recipientId);
            if (!$lead) {
                continue;
            }

            $orphan = V2Conversation::query()
                ->where('user_id', $userId)
                ->where('provider', 'linkedin')
                ->where('lead_id', $lead->id)
                ->managedByCallManager()
                ->where(function ($query) use ($chatId) {
                    $query->whereNull('provider_chat_id')
                        ->orWhere('provider_chat_id', $chatId);
                })
                ->orderByRaw('provider_chat_id IS NOT NULL DESC')
                ->orderByDesc('id')
                ->first();

            if ($orphan) {
                if (!$orphan->provider_chat_id) {
                    $orphan->forceFill(['provider_chat_id' => $chatId])->save();
                }

                return $orphan;
            }
        }

        return null;
    }

    /**
     * Reuse an existing LinkedIn chat when available; otherwise start a new one.
     *
     * @param  array<string, mixed>  $messageMeta
     */
    public function dispatchOutboundToConversation(
        int $userId,
        int $organizationId,
        V2Conversation $conversation,
        string $text,
        ?string $recipientId = null,
        array $messageMeta = []
    ): V2Message {
        $text = trim($text);
        $accountId = V2IntegrationAccount::activeUnipileAccountId($userId);
        $chatId = trim((string) $conversation->provider_chat_id);

        if ($chatId !== '') {
            $message = $this->createOutboundMessage(
                $conversation->id,
                $text,
                'message',
                ['chat_id' => $chatId] + $messageMeta
            );

            ProcessOutboundOutreachJob::dispatch(
                'message',
                $userId,
                $organizationId,
                $conversation->id,
                $message->id,
                [
                    'chat_id' => $chatId,
                    'text' => $text,
                    '_unipile_account_id' => $accountId,
                ]
            );

            return $message;
        }

        $attendeeIds = $recipientId
            ? [$recipientId]
            : array_values(array_filter(Arr::get($conversation->meta ?? [], 'attendee_ids', [])));

        if ($attendeeIds !== []) {
            $resolved = $this->resolveRecipientId($userId, $organizationId, (string) $attendeeIds[0]);
            $providerId = trim((string) ($resolved['provider_id'] ?? ''));
            if ($providerId !== '') {
                $attendeeIds = [$providerId];
                $this->findOrCreateLead($userId, $providerId, is_array($resolved['profile'] ?? null) ? $resolved['profile'] : []);

                $meta = is_array($conversation->meta) ? $conversation->meta : [];
                $meta['attendee_ids'] = $attendeeIds;
                $conversation->forceFill(['meta' => $meta])->save();
            }
        }

        $message = $this->createOutboundMessage(
            $conversation->id,
            $text,
            'start_chat',
            ['attendee_ids' => $attendeeIds] + $messageMeta
        );

        ProcessOutboundOutreachJob::dispatch(
            'start_chat',
            $userId,
            $organizationId,
            $conversation->id,
            $message->id,
            [
                'attendee_ids' => $attendeeIds,
                'text' => $text,
                '_unipile_account_id' => $accountId,
            ]
        );

        return $message;
    }

    public function createOutboundMessage(
        int $conversationId,
        ?string $body,
        string $action,
        array $meta = []
    ): V2Message {
        return V2Message::query()->create([
            'conversation_id' => $conversationId,
            'direction' => 'outbound',
            'body' => $body,
            'meta' => [
                'action' => $action,
                'status' => 'queued',
            ] + $meta,
        ]);
    }

    public function markMessageResult(V2Message $message, array $result, string $status): void
    {
        $meta = is_array($message->meta) ? $message->meta : [];
        $meta['status'] = $status;
        $meta['provider_result'] = $result;

        $message->forceFill([
            'provider_message_id' => (string) (Arr::get($result, 'id')
                ?? Arr::get($result, 'message_id')
                ?? $message->provider_message_id),
            'sent_at' => $status === 'sent' ? now() : $message->sent_at,
            'meta' => $meta,
        ])->save();

        $conversation = $message->conversation;
        if ($conversation) {
            $conversation->forceFill([
                'last_message_at' => now(),
            ])->save();
        }
    }

    public function createProviderAuditEvent(
        ?int $userId,
        string $eventType,
        string $eventId,
        array $payload
    ): V2ProviderEvent {
        return V2ProviderEvent::query()->firstOrCreate(
            [
                'provider' => 'unipile',
                'event_id' => $eventId,
            ],
            [
                'user_id' => $userId,
                'event_type' => $eventType,
                'payload' => $payload,
                'processed_at' => null,
            ]
        );
    }

    public function resolveUserIdFromOrganization(int $organizationId): ?int
    {
        $membership = V2OrganizationUser::query()
            ->where('organization_id', $organizationId)
            ->where('role', 'owner')
            ->where('status', 'active')
            ->first();

        return $membership?->user_id;
    }

    public function resolveUserIdFromUnipileAccount(string $accountId): ?int
    {
        $account = V2IntegrationAccount::query()
            ->where('provider_account_id', $accountId)
            ->orWhere('meta->unipile_account_id', $accountId)
            ->orderByDesc('id')
            ->first();

        return $account?->user_id;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function primaryRecipientId(array $meta): ?string
    {
        $attendeeIds = Arr::get($meta, 'attendee_ids', []);
        if (!is_array($attendeeIds)) {
            return null;
        }

        foreach ($attendeeIds as $id) {
            $value = trim((string) $id);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function touchConversationMeta(
        V2Conversation $conversation,
        int $organizationId,
        array $meta,
        ?int $leadId
    ): V2Conversation {
        $mergedMeta = array_merge(
            is_array($conversation->meta) ? $conversation->meta : [],
            ['organization_id' => $organizationId] + $meta
        );

        $updates = ['meta' => $mergedMeta];
        if ($leadId && !$conversation->lead_id) {
            $updates['lead_id'] = $leadId;
        }

        if ($updates !== ['meta' => $conversation->meta ?? []]) {
            $conversation->forceFill($updates)->save();
        }

        return $conversation->fresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    private function extractRecipientIdsFromPayload(array $payload): array
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
            Arr::get($payload, 'data.attendee_id'),
            Arr::get($payload, 'attendee_id'),
        ] as $single) {
            if (is_string($single) && $single !== '') {
                $ids[] = $single;
            }
        }

        return array_values(array_unique($ids));
    }
}

<?php

namespace App\V2\Services;

use App\Models\User;
use App\Models\V2Conversation;
use App\Models\V2IntegrationAccount;
use App\Models\V2Message;
use App\V2\Integrations\ProviderManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class ConversationSyncService
{
    public function __construct(
        private readonly ProviderManager $providerManager,
        private readonly OutreachPersistenceService $persistence,
    ) {
    }

    /**
     * @return array{synced: int, error: string|null}
     */
    public function syncForUser(User $user, int $organizationId, int $limit = 50): array
    {
        $accountId = V2IntegrationAccount::activeUnipileAccountId($user->id);
        if (!$accountId) {
            return ['synced' => 0, 'error' => null];
        }

        try {
            $providerKey = $this->providerManager->defaultProvider();
            $messaging = $this->providerManager->messaging($providerKey);

            $response = $messaging->listChats(
                ['account_id' => $accountId, 'limit' => $limit],
                [
                    'owner_id' => (string) $user->id,
                    'organization_id' => $organizationId,
                ]
            );

            $items = \App\V2\Integrations\Unipile\UnipileProvider::extractListItems(
                is_array($response) ? $response : []
            );

            $synced = 0;
            foreach ($items as $chat) {
                if (!is_array($chat)) {
                    continue;
                }

                $chatId = (string) (Arr::get($chat, 'id') ?? Arr::get($chat, 'chat_id') ?? '');
                if ($chatId === '') {
                    continue;
                }

                $attendeeIds = $this->extractAttendeeIds($chat);
                $leadId = null;
                if ($attendeeIds !== []) {
                    $leadId = $this->persistence->findOrCreateLead($user->id, $attendeeIds[0])?->id;
                }

                $prospectName = $this->extractProspectName($chat);
                $conversation = $this->persistence->findOrCreateConversation(
                    $user->id,
                    $organizationId,
                    $leadId,
                    $chatId,
                    array_filter([
                        'attendee_ids' => $attendeeIds !== [] ? $attendeeIds : null,
                        'prospect_name' => $prospectName,
                        'source' => 'unipile_sync',
                    ])
                );

                // v2 Chat: last_message_timestamp (v1 was timestamp).
                $lastAt = Arr::get($chat, 'last_message_timestamp')
                    ?? Arr::get($chat, 'last_message_at')
                    ?? Arr::get($chat, 'last_message.timestamp')
                    ?? Arr::get($chat, 'timestamp')
                    ?? Arr::get($chat, 'updated_at');
                if ($lastAt) {
                    try {
                        $conversation->forceFill([
                            'last_message_at' => Carbon::parse((string) $lastAt),
                        ])->save();
                    } catch (\Throwable) {
                        // ignore invalid timestamps from provider
                    }
                }

                $preview = trim((string) (
                    Arr::get($chat, 'last_message.text')
                    ?? Arr::get($chat, 'last_message.body')
                    ?? Arr::get($chat, 'preview')
                    ?? Arr::get($chat, 'snippet')
                    ?? ''
                ));
                if ($preview !== '') {
                    $this->ensurePreviewMessage($conversation->id, $preview);
                }

                $synced++;
            }

            $this->attachOrphanConversations($user->id);

            return ['synced' => $synced, 'error' => null];
        } catch (\Throwable $e) {
            return ['synced' => 0, 'error' => $e->getMessage()];
        }
    }

    private function attachOrphanConversations(int $userId): void
    {
        $orphans = V2Conversation::query()
            ->where('user_id', $userId)
            ->whereNull('provider_chat_id')
            ->whereNotNull('lead_id')
            ->get();

        foreach ($orphans as $orphan) {
            $lead = $orphan->lead;
            if (!$lead?->provider_profile_id) {
                continue;
            }

            $match = V2Conversation::query()
                ->where('user_id', $userId)
                ->whereNotNull('provider_chat_id')
                ->where('lead_id', $lead->id)
                ->where('id', '!=', $orphan->id)
                ->first();

            if (!$match) {
                continue;
            }

            V2Message::query()->where('conversation_id', $orphan->id)->update([
                'conversation_id' => $match->id,
            ]);

            app(InboxConversationResolverService::class)->retargetApprovalConversationIds(
                $userId,
                (int) $orphan->id,
                (int) $match->id,
            );

            $orphan->delete();
        }
    }

    private function ensurePreviewMessage(int $conversationId, string $preview): void
    {
        $exists = V2Message::query()
            ->where('conversation_id', $conversationId)
            ->where('body', $preview)
            ->exists();

        if ($exists) {
            return;
        }

        V2Message::query()->create([
            'conversation_id' => $conversationId,
            'direction' => 'inbound',
            'body' => $preview,
            'received_at' => now(),
            'meta' => ['source' => 'unipile_sync_preview'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $chat
     * @return array<int, string>
     */
    private function extractAttendeeIds(array $chat): array
    {
        $ids = [];

        // Unipile v2 Chat 1:1: user_id + user.id. Groups: participants[].user.id.
        // Attendees API / attendees[] were removed in v2.
        foreach ([
            Arr::get($chat, 'user_id'),
            Arr::get($chat, 'user.id'),
            Arr::get($chat, 'user.public_identifier'),
        ] as $single) {
            $value = trim((string) ($single ?? ''));
            if ($value !== '') {
                $ids[] = $value;
            }
        }

        foreach ([
            Arr::get($chat, 'participants'),
            Arr::get($chat, 'attendees'),
            Arr::get($chat, 'attendee_ids'),
        ] as $group) {
            if (!is_array($group)) {
                continue;
            }

            foreach ($group as $attendee) {
                if (is_string($attendee) && $attendee !== '') {
                    $ids[] = $attendee;
                    continue;
                }

                if (!is_array($attendee)) {
                    continue;
                }

                $id = (string) (
                    Arr::get($attendee, 'user.id')
                    ?? Arr::get($attendee, 'user_id')
                    ?? Arr::get($attendee, 'provider_id')
                    ?? Arr::get($attendee, 'id')
                    ?? Arr::get($attendee, 'attendee_provider_id')
                    ?? Arr::get($attendee, 'public_identifier')
                    ?? Arr::get($attendee, 'user.public_identifier')
                    ?? ''
                );
                if ($id !== '') {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string, mixed>  $chat
     */
    private function extractProspectName(array $chat): ?string
    {
        foreach ([
            Arr::get($chat, 'user.display_name'),
            Arr::get($chat, 'user.first_name'),
            Arr::get($chat, 'name'),
        ] as $direct) {
            $name = trim((string) ($direct ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        foreach ([
            Arr::get($chat, 'participants', []),
            Arr::get($chat, 'attendees', []),
        ] as $group) {
            if (! is_array($group)) {
                continue;
            }

            foreach ($group as $attendee) {
                if (! is_array($attendee)) {
                    continue;
                }

                $name = trim((string) (
                    Arr::get($attendee, 'user.display_name')
                    ?? Arr::get($attendee, 'display_name')
                    ?? Arr::get($attendee, 'name')
                    ?? Arr::get($attendee, 'full_name')
                    ?? ''
                ));
                if ($name !== '') {
                    return $name;
                }
            }
        }

        return null;
    }
}

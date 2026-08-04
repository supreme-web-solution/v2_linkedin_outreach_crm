<?php

namespace App\V2\Services;

use App\Models\V2Conversation;
use App\Models\V2Message;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InboxUnreadService
{
    public function isUnread(V2Conversation $conversation): bool
    {
        $latestInbound = $this->latestInboundMessage($conversation);
        if ($latestInbound === null) {
            return false;
        }

        $lastReadAt = $this->lastReadAt($conversation);
        if ($lastReadAt === null) {
            return true;
        }

        $inboundAt = $this->messageTimestamp($latestInbound);
        if ($inboundAt === null) {
            return false;
        }

        return $lastReadAt->lt($inboundAt);
    }

    public function markAsRead(V2Conversation $conversation): void
    {
        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $meta['last_read_at'] = now()->toIso8601String();

        $conversation->forceFill(['meta' => $meta])->save();
    }

    public function unreadCountForUser(int $userId, ?string $platform = null): int
    {
        $query = V2Conversation::query()
            ->where('user_id', $userId)
            ->forUnifiedInbox();

        if ($platform !== null && $platform !== '') {
            $query->where('provider', $platform);
        }

        $this->constrainToUnread($query);

        return (int) $query->count();
    }

    public function firstUnreadConversationId(int $userId, string $platform): ?int
    {
        $query = V2Conversation::query()
            ->where('user_id', $userId)
            ->forInboxPlatform($platform)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');

        $this->constrainToUnread($query);

        return $query->value('id');
    }

    /**
     * @return array<string, int>
     */
    public function unreadCountsByPlatform(int $userId, array $platforms): array
    {
        if ($platforms === []) {
            return [];
        }

        $query = V2Conversation::query()
            ->where('user_id', $userId)
            ->forUnifiedInbox()
            ->whereIn('provider', $platforms);

        $this->constrainToUnread($query);

        $counts = $query
            ->selectRaw('provider, COUNT(*) as aggregate')
            ->groupBy('provider')
            ->pluck('aggregate', 'provider');

        $result = [];
        foreach ($platforms as $platform) {
            $result[$platform] = (int) ($counts[$platform] ?? 0);
        }

        return $result;
    }

    /**
     * Batch unread flags for a page of conversations (avoids N+1).
     *
     * @param  Collection<int, V2Conversation>  $conversations
     * @return array<int, bool> keyed by conversation id
     */
    public function unreadMap(Collection $conversations): array
    {
        if ($conversations->isEmpty()) {
            return [];
        }

        $ids = $conversations->pluck('id')->all();
        $latestInboundAt = V2Message::query()
            ->whereIn('conversation_id', $ids)
            ->where('direction', 'inbound')
            ->selectRaw('conversation_id, MAX(COALESCE(received_at, created_at)) as latest_at')
            ->groupBy('conversation_id')
            ->pluck('latest_at', 'conversation_id');

        $map = [];
        foreach ($conversations as $conversation) {
            $rawAt = $latestInboundAt[$conversation->id] ?? null;
            if ($rawAt === null || $rawAt === '') {
                $map[$conversation->id] = false;
                continue;
            }

            try {
                $inboundAt = Carbon::parse($rawAt);
            } catch (\Throwable) {
                $map[$conversation->id] = false;
                continue;
            }

            $lastReadAt = $this->lastReadAt($conversation);
            $map[$conversation->id] = $lastReadAt === null || $lastReadAt->lt($inboundAt);
        }

        return $map;
    }

    /**
     * Conversations with a newer inbound message than meta.last_read_at (or never read).
     */
    private function constrainToUnread(Builder $query): void
    {
        $query->whereExists(function ($sub) {
            $sub->select(DB::raw(1))
                ->from('v2_messages as m')
                ->whereColumn('m.conversation_id', 'v2_conversations.id')
                ->where('m.direction', 'inbound')
                ->whereRaw(
                    'COALESCE(m.received_at, m.created_at) > COALESCE(
                        CAST(
                            REPLACE(
                                LEFT(
                                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(v2_conversations.meta, \'$.last_read_at\')), \'\'),
                                    19
                                ),
                                \'T\',
                                \' \'
                            ) AS DATETIME
                        ),
                        \'1970-01-01 00:00:00\'
                    )'
                );
        });
    }

    private function lastReadAt(V2Conversation $conversation): ?Carbon
    {
        $raw = Arr::get($conversation->meta ?? [], 'last_read_at');
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    private function latestInboundMessage(V2Conversation $conversation): ?V2Message
    {
        return V2Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'inbound')
            ->orderByDesc('received_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    private function messageTimestamp(V2Message $message): ?Carbon
    {
        $timestamp = $message->received_at ?? $message->sent_at ?? $message->created_at;

        return $timestamp ? Carbon::parse($timestamp) : null;
    }
}

<?php

namespace App\V2\Services;

use App\Models\V2Conversation;
use App\Models\V2Message;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

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
            $query->forInboxPlatform($platform);
        }

        return $query
            ->get()
            ->filter(fn (V2Conversation $conversation) => $this->isUnread($conversation))
            ->count();
    }

    public function firstUnreadConversationId(int $userId, string $platform): ?int
    {
        $conversations = V2Conversation::query()
            ->where('user_id', $userId)
            ->forInboxPlatform($platform)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get();

        foreach ($conversations as $conversation) {
            if ($this->isUnread($conversation)) {
                return $conversation->id;
            }
        }

        return null;
    }

    /**
     * @return array<string, int>
     */
    public function unreadCountsByPlatform(int $userId, array $platforms): array
    {
        $counts = [];
        foreach ($platforms as $platform) {
            $counts[$platform] = $this->unreadCountForUser($userId, $platform);
        }

        return $counts;
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

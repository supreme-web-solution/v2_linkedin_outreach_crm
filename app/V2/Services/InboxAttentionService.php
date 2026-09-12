<?php

namespace App\V2\Services;

use App\Models\V2Conversation;
use App\Models\V2Message;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Which inbox threads need the user or Soci — not only "unread" (opening inbox marks read).
 */
class InboxAttentionService
{
    /**
     * @param  Collection<int, V2Conversation>  $conversations
     * @return array<int, bool>
     */
    public function needsAttentionMap(Collection $conversations): array
    {
        if ($conversations->isEmpty()) {
            return [];
        }

        $ids = $conversations->pluck('id')->all();
        $latestIds = V2Message::query()
            ->whereIn('conversation_id', $ids)
            ->selectRaw('conversation_id, MAX(id) as latest_id')
            ->groupBy('conversation_id')
            ->pluck('latest_id', 'conversation_id');

        $latestMessages = $latestIds->isEmpty()
            ? collect()
            : V2Message::query()->whereIn('id', $latestIds->values())->get()->keyBy('conversation_id');

        $map = [];
        foreach ($conversations as $conversation) {
            $map[$conversation->id] = $this->latestMessageAwaitingReply($latestMessages->get($conversation->id));
        }

        return $map;
    }

    public function needsAttention(V2Conversation $conversation): bool
    {
        return ($this->needsAttentionMap(collect([$conversation]))[$conversation->id] ?? false);
    }

    private function latestMessageAwaitingReply(?V2Message $latest): bool
    {
        if (! $latest || $latest->direction !== 'inbound') {
            return false;
        }

        $body = trim((string) ($latest->body ?? ''));

        return $body !== '';
    }

    public function latestInboundBody(V2Conversation $conversation): string
    {
        $latest = V2Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'inbound')
            ->orderByDesc('received_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return trim((string) ($latest?->body ?? ''));
    }

    public function latestMessageAt(V2Conversation $conversation): ?Carbon
    {
        $latest = V2Message::query()
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('received_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if (! $latest) {
            return null;
        }

        $timestamp = $latest->received_at ?? $latest->sent_at ?? $latest->created_at;

        return $timestamp ? Carbon::parse($timestamp) : null;
    }
}

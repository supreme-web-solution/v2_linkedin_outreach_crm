<?php

namespace App\V2\Ai\Services;

use App\Models\User;
use App\Models\V2Conversation;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class InboxSociHandlingService
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function markHandled(
        V2Conversation $conversation,
        int $inboundMessageId,
        string $mode,
        array $extra = [],
    ): void {
        if ($inboundMessageId <= 0) {
            return;
        }

        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $meta['soci_inbound'] = array_merge([
            'last_inbound_message_id' => $inboundMessageId,
            'handled_at' => now()->toIso8601String(),
            'mode' => $mode,
        ], $extra);

        $conversation->forceFill(['meta' => $meta])->save();
    }

    public function isAlreadyHandled(V2Conversation $conversation, int $inboundMessageId): bool
    {
        if ($inboundMessageId <= 0) {
            return false;
        }

        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $handled = is_array($meta['soci_inbound'] ?? null) ? $meta['soci_inbound'] : [];

        return (int) ($handled['last_inbound_message_id'] ?? 0) === $inboundMessageId;
    }

    public function countRecentlyHandledForUser(User $user, int $days = 7): int
    {
        $cutoff = Carbon::now()->subDays(max(1, $days));

        return V2Conversation::query()
            ->where('user_id', $user->id)
            ->forUnifiedInbox()
            ->get()
            ->filter(function (V2Conversation $conversation) use ($cutoff) {
                $meta = is_array($conversation->meta) ? $conversation->meta : [];
                $handled = is_array($meta['soci_inbound'] ?? null) ? $meta['soci_inbound'] : [];
                $at = trim((string) ($handled['handled_at'] ?? ''));

                return $at !== '' && Carbon::parse($at)->gte($cutoff);
            })
            ->count();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function handlingMeta(V2Conversation $conversation): ?array
    {
        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $handled = is_array($meta['soci_inbound'] ?? null) ? $meta['soci_inbound'] : [];

        return $handled === [] ? null : $handled;
    }

    /**
     * Recent Soci-handled inbox threads (for digest / WhatsApp summaries).
     *
     * @return list<array{channel: string, name: string, mode: string, handled_at: string}>
     */
    public function recentHandledBriefs(User $user, int $hours = 6, int $limit = 3): array
    {
        $cutoff = Carbon::now()->subHours(max(1, $hours));
        $rows = [];

        foreach (V2Conversation::query()->where('user_id', $user->id)->forUnifiedInbox()->get() as $conversation) {
            $handled = $this->handlingMeta($conversation);
            if ($handled === null) {
                continue;
            }

            $at = trim((string) ($handled['handled_at'] ?? ''));
            if ($at === '') {
                continue;
            }

            try {
                if (Carbon::parse($at)->lt($cutoff)) {
                    continue;
                }
            } catch (\Throwable) {
                continue;
            }

            $meta = is_array($conversation->meta) ? $conversation->meta : [];
            $rows[] = [
                'channel' => (string) $conversation->provider,
                'name' => trim((string) ($meta['prospect_name'] ?? 'Prospect')) ?: 'Prospect',
                'mode' => (string) ($handled['mode'] ?? 'handled'),
                'handled_at' => $at,
            ];
        }

        usort($rows, fn (array $a, array $b) => strcmp($b['handled_at'], $a['handled_at']));

        return array_slice($rows, 0, max(1, $limit));
    }
}

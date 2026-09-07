<?php

namespace App\V2\Ai\Services;

use App\Models\AiZernioWebhookEvent;
use Illuminate\Database\QueryException;

class ZernioWebhookIdempotencyService
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function claim(string $eventId, string $event, array $meta = []): bool
    {
        if ($eventId === '') {
            return true;
        }

        try {
            AiZernioWebhookEvent::query()->create([
                'event_id' => $eventId,
                'event' => $event,
                'status' => 'processing',
                'meta' => $meta,
                'processed_at' => now(),
            ]);

            return true;
        } catch (QueryException $e) {
            if ($this->isDuplicate($e)) {
                return false;
            }

            throw $e;
        }
    }

    public function markProcessed(string $eventId): void
    {
        if ($eventId === '') {
            return;
        }

        AiZernioWebhookEvent::query()
            ->where('event_id', $eventId)
            ->update(['status' => 'processed']);
    }

    private function isDuplicate(QueryException $e): bool
    {
        $code = (string) ($e->errorInfo[1] ?? '');
        $message = strtolower($e->getMessage());

        return in_array($code, ['1062', '23505', '19'], true)
            || str_contains($message, 'unique constraint failed');
    }
}

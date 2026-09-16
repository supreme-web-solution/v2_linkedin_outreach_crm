<?php

namespace App\V2\Ai\Services;

use App\Jobs\V2\ProcessWebAiChatJob;
use App\Jobs\V2\RunWebAgentTurnJob;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Detects web chat turns stuck in "Thinking…" with no queue worker job
 * (e.g. worker restarted) and re-dispatches the agent turn.
 *
 * Production uses Horizon/Redis — never assume only the database `jobs` table.
 */
class WebChatTurnRecoveryService
{
    public function __construct(
        private readonly WebChatProcessingService $processing,
        private readonly AgentOrchestrator $orchestrator,
    ) {}

    /**
     * @return array{after_message_id:int, processing:array{active:bool, label:string}}|null
     */
    public function resolvePendingTurn(User $user, int $organizationId, AiConversation $conversation): ?array
    {
        $conversation = $conversation->fresh() ?? $conversation;
        $pending = $this->processing->pendingTurnSnapshot($conversation);

        if ($pending === null) {
            return null;
        }

        $userMessageId = (int) ($pending['after_message_id'] ?? 0);
        if ($userMessageId <= 0) {
            return $pending;
        }

        if ($this->hasQueuedTurnJob($conversation->id, $userMessageId)) {
            return $pending;
        }

        if (! $this->isStale($conversation, $userMessageId)) {
            return $pending;
        }

        $attempts = $this->redispatchAttempts($conversation);
        $maxAttempts = max(1, (int) config('socifusion_ai.web_chat_redispatch_attempts', 3));
        $staleSeconds = max(30, (int) config('socifusion_ai.web_chat_stale_seconds', 90));

        if ($attempts >= $maxAttempts) {
            $this->orchestrator->failQueuedWebTurn(
                user: $user,
                organizationId: $organizationId,
                conversationId: (int) $conversation->id,
                userMessageId: $userMessageId,
                exception: new \RuntimeException('Web chat turn stalled with no queue worker job.'),
            );

            return null;
        }

        if ($attempts > 0) {
            $lastRedispatch = $this->parseTimestamp(
                is_array($conversation->meta) ? ($conversation->meta['web_chat_pending']['last_redispatch_at'] ?? null) : null
            );
            if ($lastRedispatch !== null && $lastRedispatch->copy()->addSeconds($staleSeconds)->isFuture()) {
                return $pending;
            }
        }

        $userMessage = AiMessage::query()
            ->whereKey($userMessageId)
            ->where('conversation_id', $conversation->id)
            ->where('role', 'user')
            ->first();

        if (! $userMessage) {
            $this->processing->clearAll($conversation);

            return null;
        }

        $promptMessage = $this->promptMessageFor($conversation, $userMessage);

        $this->recordRedispatch($conversation, $userMessageId, $promptMessage, $organizationId, $attempts + 1);
        $this->processing->start($conversation->fresh() ?? $conversation, 'Resuming…');

        RunWebAgentTurnJob::dispatch(
            $user->id,
            $organizationId,
            (int) $conversation->id,
            $userMessageId,
            $promptMessage,
        );

        return [
            'after_message_id' => $userMessageId,
            'processing' => [
                'active' => true,
                'label' => 'Resuming…',
            ],
        ];
    }

    public function hasQueuedTurnJob(int $conversationId, int $userMessageId): bool
    {
        if ($this->hasUniqueAgentTurnLock($conversationId, $userMessageId)) {
            return true;
        }

        if ($this->hasDatabaseQueuedTurnJob($conversationId, $userMessageId)) {
            return true;
        }

        return $this->hasRedisQueuedTurnJob($conversationId, $userMessageId);
    }

    public function isStale(AiConversation $conversation, int $userMessageId): bool
    {
        $staleSeconds = max(30, (int) config('socifusion_ai.web_chat_stale_seconds', 90));
        $jobTimeout = max(120, (int) config('socifusion_ai.web_chat_job_timeout', 600));
        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $pending = is_array($meta['web_chat_pending'] ?? null) ? $meta['web_chat_pending'] : [];
        $processing = is_array($meta['web_chat_processing'] ?? null) ? $meta['web_chat_processing'] : [];

        $agentStartedAt = $this->parseTimestamp($pending['agent_started_at'] ?? null);
        if ($agentStartedAt !== null && $agentStartedAt->copy()->addSeconds($jobTimeout)->isFuture()) {
            return false;
        }

        $processingAt = $this->parseTimestamp($processing['updated_at'] ?? null);
        if ($processingAt !== null && $processingAt->copy()->addSeconds(min($staleSeconds, 180))->isFuture()) {
            // Worker is active (including long Mindcase polls) — do not re-dispatch yet.
            return false;
        }

        $anchor = $this->parseTimestamp($pending['queued_at'] ?? null)
            ?? $this->parseTimestamp($pending['last_redispatch_at'] ?? null)
            ?? $processingAt;

        if ($anchor === null) {
            $userMessage = AiMessage::query()->find($userMessageId);
            $anchor = $userMessage?->created_at;
        }

        if ($anchor === null) {
            return true;
        }

        return Carbon::parse($anchor)->addSeconds($staleSeconds)->isPast();
    }

    private function hasUniqueAgentTurnLock(int $conversationId, int $userMessageId): bool
    {
        try {
            $probe = new RunWebAgentTurnJob(0, 0, $conversationId, $userMessageId, '');
            $key = UniqueLock::getKey($probe);
            $lock = Cache::lock($key, 1);

            // If we cannot acquire, another worker already holds the unique lock
            // (queued or currently processing).
            if (! $lock->get()) {
                return true;
            }

            $lock->release();
        } catch (Throwable) {
            // Fall through to queue payload checks.
        }

        return false;
    }

    private function hasDatabaseQueuedTurnJob(int $conversationId, int $userMessageId): bool
    {
        if (! $this->jobsTableExists()) {
            return false;
        }

        try {
            $patterns = [
                '%RunWebAgentTurnJob%',
                '%"conversationId";i:'.$conversationId.'%',
                '%"userMessageId";i:'.$userMessageId.'%',
            ];

            $query = DB::table('jobs');
            foreach ($patterns as $pattern) {
                $query->where('payload', 'like', $pattern);
            }

            if ($query->exists()) {
                return true;
            }

            $coordinatorPatterns = [
                '%ProcessWebAiChatJob%',
                '%"conversationId";i:'.$conversationId.'%',
                '%"userMessageId";i:'.$userMessageId.'%',
            ];

            $coordinatorQuery = DB::table('jobs');
            foreach ($coordinatorPatterns as $pattern) {
                $coordinatorQuery->where('payload', 'like', $pattern);
            }

            return $coordinatorQuery->exists();
        } catch (Throwable) {
            return false;
        }
    }

    private function hasRedisQueuedTurnJob(int $conversationId, int $userMessageId): bool
    {
        $driver = (string) config('queue.default');
        if (! in_array($driver, ['redis', 'horizon'], true) && ! $this->redisQueueConfigured()) {
            // Horizon production still uses redis connection even when default is redis.
            if (! extension_loaded('redis') && ! class_exists(\Predis\Client::class)) {
                return false;
            }
        }

        $queues = array_values(array_unique(array_filter([
            (string) config('socifusion_ai.web_chat_agent_queue_name', 'default'),
            (string) config('socifusion_ai.web_chat_queue_name', 'webhooks'),
            'default',
            'webhooks',
        ])));

        try {
            $connectionName = (string) config('queue.connections.redis.connection', 'default');
            $redis = Redis::connection($connectionName);

            foreach ($queues as $queue) {
                $base = 'queues:'.$queue;
                $payloads = array_merge(
                    $this->redisListValues($redis, $base),
                    $this->redisZsetValues($redis, $base.':delayed'),
                    $this->redisZsetValues($redis, $base.':reserved'),
                );

                foreach ($payloads as $payload) {
                    if ($this->payloadMatchesTurn($payload, $conversationId, $userMessageId)) {
                        return true;
                    }
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    /**
     * @param  \Illuminate\Redis\Connections\Connection|\Redis  $redis
     * @return list<string>
     */
    private function redisListValues(mixed $redis, string $key): array
    {
        try {
            $values = $redis->lrange($key, 0, 250);

            return is_array($values) ? array_map('strval', $values) : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  \Illuminate\Redis\Connections\Connection|\Redis  $redis
     * @return list<string>
     */
    private function redisZsetValues(mixed $redis, string $key): array
    {
        try {
            $values = $redis->zrange($key, 0, 250);

            return is_array($values) ? array_map('strval', $values) : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function payloadMatchesTurn(string $payload, int $conversationId, int $userMessageId): bool
    {
        $decoded = $payload;
        $json = json_decode($payload, true);
        if (is_array($json)) {
            $decoded = json_encode($json) ?: $payload;
            if (isset($json['data']['command'])) {
                $decoded .= ' '.$json['data']['command'];
            }
            if (isset($json['displayName'])) {
                $decoded .= ' '.$json['displayName'];
            }
        }

        $hasAgent = str_contains($decoded, 'RunWebAgentTurnJob')
            || str_contains($decoded, ProcessWebAiChatJob::class)
            || str_contains($decoded, 'ProcessWebAiChatJob');

        if (! $hasAgent) {
            return false;
        }

        $hasConversation = str_contains($decoded, '"conversationId";i:'.$conversationId.';')
            || str_contains($decoded, '"conversationId":'.$conversationId)
            || str_contains($decoded, 'conversationId";i:'.$conversationId);

        $hasMessage = str_contains($decoded, '"userMessageId";i:'.$userMessageId.';')
            || str_contains($decoded, '"userMessageId":'.$userMessageId)
            || str_contains($decoded, 'userMessageId";i:'.$userMessageId);

        return $hasConversation && $hasMessage;
    }

    private function redisQueueConfigured(): bool
    {
        try {
            return (string) config('queue.connections.redis.driver') === 'redis'
                || Queue::getDefaultDriver() === 'redis';
        } catch (Throwable) {
            return false;
        }
    }

    private function promptMessageFor(AiConversation $conversation, AiMessage $userMessage): string
    {
        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $pending = is_array($meta['web_chat_pending'] ?? null) ? $meta['web_chat_pending'] : [];
        $stored = trim((string) ($pending['prompt_message'] ?? ''));

        if ($stored !== '') {
            return $stored;
        }

        return trim((string) $userMessage->content);
    }

    private function redispatchAttempts(AiConversation $conversation): int
    {
        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $pending = is_array($meta['web_chat_pending'] ?? null) ? $meta['web_chat_pending'] : [];

        return max(0, (int) ($pending['redispatch_attempts'] ?? 0));
    }

    private function recordRedispatch(
        AiConversation $conversation,
        int $userMessageId,
        string $promptMessage,
        int $organizationId,
        int $attempts,
    ): void {
        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $meta['web_chat_pending'] = [
            'user_message_id' => $userMessageId,
            'prompt_message' => $promptMessage,
            'organization_id' => $organizationId,
            'queued_at' => Carbon::now()->toIso8601String(),
            'redispatch_attempts' => $attempts,
            'last_redispatch_at' => Carbon::now()->toIso8601String(),
        ];
        $conversation->forceFill(['meta' => $meta])->save();
    }

    private function parseTimestamp(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function jobsTableExists(): bool
    {
        try {
            return DB::getSchemaBuilder()->hasTable('jobs');
        } catch (Throwable) {
            return false;
        }
    }
}

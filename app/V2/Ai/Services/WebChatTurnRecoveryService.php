<?php

namespace App\V2\Ai\Services;

use App\Jobs\V2\RunWebAgentTurnJob;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Detects web chat turns stuck in "Thinking…" with no queue worker job
 * (e.g. worker restarted) and re-dispatches the agent turn.
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
        if (! $this->jobsTableExists()) {
            return false;
        }

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
        } catch (\Throwable) {
            return null;
        }
    }

    private function jobsTableExists(): bool
    {
        try {
            return DB::getSchemaBuilder()->hasTable('jobs');
        } catch (\Throwable) {
            return false;
        }
    }
}

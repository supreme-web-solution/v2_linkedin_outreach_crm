<?php

namespace App\V2\Ai\Services;

use App\Models\AiConversation;
use App\Models\AiMessage;
use Illuminate\Support\Carbon;

class WebChatProcessingService
{
    public function markPending(
        AiConversation $conversation,
        int $userMessageId,
        ?string $promptMessage = null,
        ?int $organizationId = null,
    ): void {
        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $meta['web_chat_pending'] = [
            'user_message_id' => $userMessageId,
            'prompt_message' => $promptMessage !== null ? trim($promptMessage) : null,
            'organization_id' => $organizationId,
            'queued_at' => Carbon::now()->toIso8601String(),
            'redispatch_attempts' => 0,
        ];
        $conversation->forceFill(['meta' => $meta])->save();
    }

    public function markAgentRunning(AiConversation $conversation): void
    {
        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $pending = is_array($meta['web_chat_pending'] ?? null) ? $meta['web_chat_pending'] : [];
        $pending['agent_started_at'] = Carbon::now()->toIso8601String();
        $meta['web_chat_pending'] = $pending;
        $conversation->forceFill(['meta' => $meta])->save();
    }

    public function start(AiConversation $conversation, string $label = 'Thinking…'): void
    {
        $this->write($conversation, 'thinking', $label);
    }

    public function update(AiConversation $conversation, string $stage, string $label): void
    {
        $this->write($conversation, $stage, $label);
    }

    public function postProgressReply(
        AiConversation $conversation,
        string $content,
        bool $isFinal = false,
        ?string $nextLabel = null,
    ): void {
        $text = trim($content);
        if ($text === '') {
            return;
        }

        AiMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $text,
            'meta' => [
                'channel' => 'web',
                'progress' => ! $isFinal,
            ],
        ]);

        if ($isFinal) {
            return;
        }

        $this->update($conversation, 'searching', $nextLabel ?? 'Working…');
    }

    public function clear(AiConversation $conversation): void
    {
        $this->clearAll($conversation);
    }

    public function clearAll(AiConversation $conversation): void
    {
        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        unset($meta['web_chat_processing'], $meta['web_chat_pending']);
        $conversation->forceFill(['meta' => $meta])->save();
    }

    /**
     * @return array{active:bool, stage?:string, label?:string, updated_at?:string}|null
     */
    public function snapshot(AiConversation $conversation): ?array
    {
        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $processing = is_array($meta['web_chat_processing'] ?? null) ? $meta['web_chat_processing'] : null;

        if (! $processing || empty($processing['active'])) {
            return null;
        }

        return [
            'active' => true,
            'stage' => (string) ($processing['stage'] ?? 'thinking'),
            'label' => (string) ($processing['label'] ?? 'Thinking…'),
            'updated_at' => (string) ($processing['updated_at'] ?? ''),
        ];
    }

    /**
     * @return array{after_message_id:int, processing:array{active:bool, label:string}}|null
     */
    public function pendingTurnSnapshot(AiConversation $conversation): ?array
    {
        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $pending = is_array($meta['web_chat_pending'] ?? null) ? $meta['web_chat_pending'] : null;
        $userMessageId = (int) ($pending['user_message_id'] ?? 0);

        if ($userMessageId <= 0) {
            return $this->pendingTurnFromMessages($conversation);
        }

        if ($this->hasAssistantReplyAfter($conversation, $userMessageId)) {
            $this->clearAll($conversation);

            return null;
        }

        $processing = $this->snapshot($conversation);

        return [
            'after_message_id' => $userMessageId,
            'processing' => $processing ?? [
                'active' => true,
                'label' => 'Thinking…',
            ],
        ];
    }

    /**
     * @return array{after_message_id:int, processing:array{active:bool, label:string}}|null
     */
    private function pendingTurnFromMessages(AiConversation $conversation): ?array
    {
        $lastUser = AiMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'user')
            ->orderByDesc('id')
            ->first();

        if (! $lastUser) {
            return null;
        }

        $meta = is_array($lastUser->meta) ? $lastUser->meta : [];
        if (empty($meta['queued'])) {
            return null;
        }

        if ($this->hasAssistantReplyAfter($conversation, (int) $lastUser->id)) {
            return null;
        }

        $processing = $this->snapshot($conversation);

        return [
            'after_message_id' => (int) $lastUser->id,
            'processing' => $processing ?? [
                'active' => true,
                'label' => 'Thinking…',
            ],
        ];
    }

    private function hasAssistantReplyAfter(AiConversation $conversation, int $userMessageId): bool
    {
        $replies = AiMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->where('id', '>', $userMessageId)
            ->get(['content', 'meta']);

        foreach ($replies as $reply) {
            if ($this->isFinalAssistantReply($reply->content, $reply->meta)) {
                return true;
            }
        }

        return false;
    }

    public function isFinalAssistantReply(mixed $content, mixed $meta = null): bool
    {
        if (is_array($meta) && ! empty($meta['progress'])) {
            return false;
        }

        return $this->isRealAssistantReply($content);
    }

    private function isRealAssistantReply(mixed $content): bool
    {
        $text = trim((string) $content);

        return $text !== '' && ! preg_match('/^\.{1,3}$|^…$/', $text);
    }

    private function write(AiConversation $conversation, string $stage, string $label): void
    {
        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $meta['web_chat_processing'] = [
            'active' => true,
            'stage' => $stage,
            'label' => $label,
            'updated_at' => Carbon::now()->toIso8601String(),
        ];
        $conversation->forceFill(['meta' => $meta])->save();
    }
}

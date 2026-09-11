<?php

namespace App\V2\Ai\Services;

use App\Models\AiConversation;
use App\Models\User;
use App\V2\Ai\Enums\AiToolPermission;
use Illuminate\Support\Str;

class PromptObservabilityService
{
    public function __construct(
        private readonly AiActionLogService $actionLogs,
    ) {}

    /**
     * @param  array<string, mixed>  $signals
     */
    public function classifyPreAgentPrompt(string $message, ?array $control, array $signals = []): string
    {
        if (($control['handled'] ?? false) || ! empty($control['rewrite'])) {
            return 'understood';
        }

        if (($signals['is_informational'] ?? false)
            || ($signals['is_discovery'] ?? false)
            || ($signals['is_outreach'] ?? false)
            || ($signals['is_setup_only'] ?? false)) {
            return 'understood';
        }

        $trimmed = trim(Str::lower($message));
        if ($trimmed === '') {
            return 'ambiguous_empty';
        }

        if (strlen($trimmed) <= 3) {
            return 'ambiguous_short';
        }

        if (preg_match('/^(yes|ok|okay|go ahead|do it|confirm|proceed)$/i', $trimmed)) {
            return ! empty($signals['has_pending_approvals'])
                ? 'understood'
                : 'ambiguous_no_pending_action';
        }

        return 'unknown_pre_llm';
    }

    /**
     * @return array{reply:string,used_fallback:bool,reason:string}
     */
    public function enforceClarifierFallback(string $userMessage, string $reply): array
    {
        $normalized = trim($reply);
        $lower = Str::lower($normalized);
        $generic = [
            '',
            'done.',
            'done',
            'ok.',
            'ok',
            'okay.',
            'okay',
            'completed.',
            'all set.',
        ];

        if (in_array($lower, $generic, true)) {
            return [
                'reply' => $this->clarifierReply($userMessage),
                'used_fallback' => true,
                'reason' => $normalized === '' ? 'empty_reply' : 'generic_reply',
            ];
        }

        return [
            'reply' => $reply,
            'used_fallback' => false,
            'reason' => 'none',
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $output
     */
    public function logPromptEvent(
        User $user,
        int $organizationId,
        ?AiConversation $conversation,
        string $status,
        array $input,
        array $output = [],
    ): void {
        $this->actionLogs->log(
            user: $user,
            organizationId: $organizationId,
            tool: 'prompt_observer',
            permission: AiToolPermission::Read,
            status: Str::limit($status, 32, ''),
            conversation: $conversation,
            input: $input,
            output: $output !== [] ? $output : null,
            error: null,
            durationMs: null,
        );
    }

    private function clarifierReply(string $message): string
    {
        $snippet = trim(Str::limit($message, 140, '...'));
        $prefix = $snippet !== '' ? "I want to make sure I do this right: \"{$snippet}\".\n" : '';

        return $prefix
            ."Can you clarify one thing so I can execute correctly?\n"
            .'• Goal: status update, find leads, launch campaign, or inbox reply?\n'
            .'• Channel (if outreach): LinkedIn, Instagram, Email, WhatsApp, or Telegram';
    }
}

<?php

namespace App\V2\Ai\Services;

use App\Models\AiActionApproval;
use App\Models\AiConversation;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Resend staged inbox replies without LLM read-only loops ("try again", pick a name, etc.).
 */
class InboxReplyRetryPreflightService
{
    public function __construct(
        private readonly UserTurnIntentService $intent,
        private readonly CommandCenterService $commandCenter,
    ) {}

    /**
     * @return array{handled:bool, reply?:string, approval?:AiActionApproval|null}|null
     */
    public function tryHandle(
        User $user,
        int $organizationId,
        AiConversation $conversation,
        string $message,
    ): ?array {
        $retryable = $this->commandCenter->retryableDraftReplyApprovals($user, $organizationId);
        if ($retryable->isEmpty()) {
            return null;
        }

        $wantsRetry = $this->intent->isInboxReplyRetryRequest($message);
        $isSelection = $this->intent->isInboxResendSelection($message);

        if (! $wantsRetry && ! $isSelection) {
            return null;
        }

        $selection = $this->intent->extractInboxResendSelection($message);
        $matched = $this->matchApproval($retryable, $selection);

        if ($retryable->count() > 1 && $matched === null && $isSelection) {
            return [
                'handled' => true,
                'reply' => $this->formatDisambiguation($retryable),
                'approval' => null,
            ];
        }

        if ($retryable->count() > 1 && $matched === null && $wantsRetry) {
            return [
                'handled' => true,
                'reply' => implode("\n\n", [
                    'Which message should I resend?',
                    $this->formatDisambiguation($retryable, numbered: true),
                ]),
                'approval' => null,
            ];
        }

        $approval = $matched ?? $retryable->first();
        if (! $approval instanceof AiActionApproval) {
            return null;
        }

        $result = $this->commandCenter->handleControlCommand(
            $user,
            $organizationId,
            'LAUNCH '.$approval->id,
        );

        return [
            'handled' => (bool) ($result['handled'] ?? true),
            'reply' => (string) ($result['reply'] ?? 'Sending your reply…'),
            'approval' => $result['approval'] ?? $approval,
        ];
    }

    /**
     * @param  Collection<int, AiActionApproval>  $retryable
     * @param  array{name: string|null, conversation_id: int|null}  $selection
     */
    private function matchApproval(Collection $retryable, array $selection): ?AiActionApproval
    {
        $conversationId = (int) ($selection['conversation_id'] ?? 0);
        if ($conversationId > 0) {
            $byConversation = $retryable->first(
                fn (AiActionApproval $approval) => (int) data_get($approval->payload, 'conversation_id') === $conversationId
            );
            if ($byConversation instanceof AiActionApproval) {
                return $byConversation;
            }
        }

        $name = trim((string) ($selection['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $needle = Str::lower($name);

        return $retryable->first(function (AiActionApproval $approval) use ($needle) {
            $prospect = Str::lower(trim((string) data_get($approval->payload, 'prospect_name', '')));
            if ($prospect === '') {
                return false;
            }

            return $prospect === $needle
                || str_starts_with($prospect, $needle)
                || str_starts_with($needle, $prospect);
        });
    }

    /**
     * @param  Collection<int, AiActionApproval>  $retryable
     */
    private function formatDisambiguation(Collection $retryable, bool $numbered = false): string
    {
        $lines = [];

        foreach ($retryable->values() as $index => $approval) {
            $payload = is_array($approval->payload) ? $approval->payload : [];
            $prospect = trim((string) ($payload['prospect_name'] ?? 'Prospect'));
            $preview = trim((string) ($payload['inbound_preview'] ?? ''));
            if ($preview === '') {
                $preview = Str::limit(trim((string) ($payload['draft_text'] ?? '')), 40, '…');
            }

            $line = $prospect.($preview !== '' ? ' — "'.$preview.'"' : '');
            $lines[] = $numbered
                ? ($index + 1).'. '.$line
                : '- '.$line;
        }

        return implode("\n", $lines);
    }
}

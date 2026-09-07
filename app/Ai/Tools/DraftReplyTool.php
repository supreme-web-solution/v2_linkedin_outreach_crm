<?php

namespace App\Ai\Tools;

use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\InboxCommandCenterService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class DraftReplyTool extends GatedTool
{
    public function toolName(): string
    {
        return 'draft_reply';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Prepare;
    }

    public function description(): Stringable|string
    {
        return 'Draft a reply to an unread Unified Inbox conversation for Review & Launch. Does not send until approved.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'conversation_id' => $schema->integer()->required()->description('Unified Inbox conversation id from get_attention_queue'),
            'notes' => $schema->string()->nullable()->description('Optional guidance for tone or next step'),
        ];
    }

    protected function run(Request $request): array
    {
        $result = app(InboxCommandCenterService::class)->stageDraftReply(
            $this->context->user,
            $this->context->organizationId,
            $this->context->conversation,
            (int) $request['conversation_id'],
            $request['notes'] ?? null,
            $this->context->channel,
        );

        if ($result['blocked'] ?? false) {
            throw new \RuntimeException((string) ($result['message'] ?? 'Blocked'));
        }

        if ($result['auto_sent'] ?? false) {
            return [
                'approval_id' => $result['approval_id'],
                'plan' => $result['plan'],
                'card' => $result['card'],
                'auto_sent' => true,
                'cta' => $result['message'] ?? 'Reply sent automatically (Autopilot+).',
            ];
        }

        return [
            'approval_id' => $result['approval_id'],
            'plan' => $result['plan'],
            'card' => $result['card'],
            'cta' => $result['approval_id']
                ? 'User should Review & Launch to send (LAUNCH '.$result['approval_id'].' on WhatsApp).'
                : ($result['message'] ?? 'Copilot mode: recommendation only.'),
        ];
    }
}

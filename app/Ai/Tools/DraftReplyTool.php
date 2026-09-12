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
        return 'Draft a reply to a Unified Inbox conversation for Review & Launch. Does not send until approved. '
            .'Use conversation_id from get_attention_queue, or pass prospect_email / prospect_name (works even if the thread was already opened/read).';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'conversation_id' => $schema->integer()->nullable()->description('Unified Inbox conversation id from get_attention_queue'),
            'prospect_email' => $schema->string()->nullable()->description('Find thread by email, e.g. vickenconcept@gmail.com'),
            'prospect_name' => $schema->string()->nullable()->description('Find thread by prospect name when id unknown'),
            'notes' => $schema->string()->nullable()->description('Optional guidance for tone or next step'),
        ];
    }

    protected function run(Request $request): array
    {
        $conversationId = isset($request['conversation_id']) ? (int) $request['conversation_id'] : 0;
        if ($conversationId <= 0) {
            $resolved = app(\App\V2\Services\InboxConversationResolverService::class)->findForUser(
                $this->context->user,
                email: $request['prospect_email'] ?? null,
                prospectName: $request['prospect_name'] ?? null,
            );
            if (! $resolved) {
                throw new \RuntimeException('No inbox conversation found for that email or name. Try get_attention_queue or pass conversation_id.');
            }
            $conversationId = (int) $resolved->id;
        }

        $result = app(InboxCommandCenterService::class)->stageDraftReply(
            $this->context->user,
            $this->context->organizationId,
            $this->context->conversation,
            $conversationId,
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

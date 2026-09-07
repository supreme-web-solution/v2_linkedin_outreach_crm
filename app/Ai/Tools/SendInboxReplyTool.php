<?php

namespace App\Ai\Tools;

use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\InboxCommandCenterService;
use App\V2\Ai\Services\ReplySendFromPlanService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class SendInboxReplyTool extends GatedTool
{
    public function toolName(): string
    {
        return 'send_inbox_reply';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Execute;
    }

    public function description(): Stringable|string
    {
        return 'Draft and send an inbox reply immediately (Autopilot+). Use for hot leads when user approves sending.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'conversation_id' => $schema->integer()->required()->description('Unified Inbox conversation id'),
            'notes' => $schema->string()->nullable()->description('Optional guidance for the reply'),
            'message' => $schema->string()->nullable()->description('Optional exact reply text; if omitted Alex drafts one'),
        ];
    }

    protected function run(Request $request): array
    {
        $conversationId = (int) $request['conversation_id'];
        $message = isset($request['message']) ? trim((string) $request['message']) : '';

        if ($message !== '') {
            return app(InboxCommandCenterService::class)->sendReplyNow(
                $this->context->user,
                $this->context->organizationId,
                $conversationId,
                $message,
            );
        }

        $staged = app(InboxCommandCenterService::class)->stageDraftReply(
            $this->context->user,
            $this->context->organizationId,
            $this->context->conversation,
            $conversationId,
            $request['notes'] ?? null,
            $this->context->channel,
        );

        if ($staged['blocked'] ?? false) {
            throw new \RuntimeException((string) ($staged['message'] ?? 'Blocked'));
        }

        $approval = $staged['approval'] ?? null;
        if (! $approval) {
            throw new \RuntimeException('Could not prepare reply.');
        }

        return app(ReplySendFromPlanService::class)->sendFromApproval($approval, $this->context->user);
    }
}

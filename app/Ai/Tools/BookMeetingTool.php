<?php

namespace App\Ai\Tools;

use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\BookMeetingCommandCenterService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class BookMeetingTool extends GatedTool
{
    public function toolName(): string
    {
        return 'book_meeting';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Prepare;
    }

    public function description(): Stringable|string
    {
        return 'Stage sending a calendar booking link to a hot inbox conversation (meeting-ready reply). Requires connected calendar. '
            .'notes = optional guidance OR a finished recipient-facing message — NEVER paste operator plans like "Reply with… Thank them…".';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'conversation_id' => $schema->integer()->required()->description('Unified inbox conversation id'),
            'notes' => $schema->string()->nullable()->description(
                'Optional: finished message the prospect should read, OR short guidance for drafting. '
                .'Do NOT put action-plan text here (e.g. "Reply with the event details… Thank them…").',
            ),
        ];
    }

    protected function run(Request $request): array
    {
        return app(BookMeetingCommandCenterService::class)->stage(
            user: $this->context->user,
            organizationId: $this->context->organizationId,
            conversation: $this->context->conversation,
            inboxConversationId: (int) $request['conversation_id'],
            notes: $request['notes'] ?? null,
            surface: $this->context->channel,
        );
    }
}

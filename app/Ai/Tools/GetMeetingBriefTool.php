<?php

namespace App\Ai\Tools;

use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\MeetingBriefService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetMeetingBriefTool extends GatedTool
{
    public function toolName(): string
    {
        return 'get_meeting_brief';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Read;
    }

    public function description(): Stringable|string
    {
        return 'Pre-call brief for a booked Call Manager meeting: prospect context, talking points, recent thread.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'call_id' => $schema->integer()->nullable()->description('Call Manager call id'),
            'conversation_id' => $schema->integer()->nullable()->description('Resolve call from a conversation thread'),
        ];
    }

    protected function run(Request $request): array
    {
        $callId = isset($request['call_id']) ? (int) $request['call_id'] : null;
        $conversationId = isset($request['conversation_id']) ? (int) $request['conversation_id'] : null;

        return app(MeetingBriefService::class)->forUser(
            $this->context->user,
            $this->context->organizationId,
            $callId,
            $conversationId,
        );
    }
}

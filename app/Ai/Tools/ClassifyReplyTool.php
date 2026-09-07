<?php

namespace App\Ai\Tools;

use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\InboxClassificationCommandCenterService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ClassifyReplyTool extends GatedTool
{
    public function toolName(): string
    {
        return 'classify_reply';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Read;
    }

    public function description(): Stringable|string
    {
        return 'Classify an inbox reply by intent, priority, stage, and recommended next action. Use conversation_id from get_attention_queue.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'conversation_id' => $schema->integer()->required()->description('Unified Inbox conversation id'),
        ];
    }

    protected function run(Request $request): array
    {
        $result = app(InboxClassificationCommandCenterService::class)->classifyConversation(
            $this->context->user,
            (int) $request['conversation_id'],
        );

        return $result;
    }
}

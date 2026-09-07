<?php

namespace App\Ai\Tools;

use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\LeadNurtureCommandCenterService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class MoveLeadToNurtureTool extends GatedTool
{
    public function toolName(): string
    {
        return 'move_lead_to_nurture';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Execute;
    }

    public function description(): Stringable|string
    {
        return 'Move a prospect to nurture (e.g. "maybe later") and pause active outreach. Default follow-up in 90 days.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'conversation_id' => $schema->integer()->nullable()->description('Unified Inbox conversation id from get_attention_queue'),
            'outreach_lead_id' => $schema->integer()->nullable()->description('Outreach lead id if known'),
            'follow_up_days' => $schema->integer()->nullable()->description('Days until follow-up (default 90)'),
            'reason' => $schema->string()->nullable()->description('Why they are being nurtured'),
        ];
    }

    protected function run(Request $request): array
    {
        $conversationId = isset($request['conversation_id']) ? (int) $request['conversation_id'] : null;
        $leadId = isset($request['outreach_lead_id']) ? (int) $request['outreach_lead_id'] : null;

        if (($conversationId ?? 0) <= 0 && ($leadId ?? 0) <= 0) {
            throw new \InvalidArgumentException('Provide conversation_id or outreach_lead_id.');
        }

        return app(LeadNurtureCommandCenterService::class)->moveToNurture(
            user: $this->context->user,
            outreachLeadId: $leadId,
            conversationId: $conversationId,
            followUpDays: (int) ($request['follow_up_days'] ?? 90),
            reason: isset($request['reason']) ? (string) $request['reason'] : null,
        );
    }
}

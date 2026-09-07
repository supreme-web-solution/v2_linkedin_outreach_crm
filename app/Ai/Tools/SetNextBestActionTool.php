<?php

namespace App\Ai\Tools;

use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\NextBestActionCommandCenterService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class SetNextBestActionTool extends GatedTool
{
    public function toolName(): string
    {
        return 'set_next_best_action';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Prepare;
    }

    public function description(): Stringable|string
    {
        return 'Stage a next best action on an outreach lead CRM record. Saved on Review & Launch.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'outreach_lead_id' => $schema->integer()->nullable()->description('Outreach lead id'),
            'conversation_id' => $schema->integer()->nullable()->description('Unified Inbox conversation id (resolves lead)'),
            'action' => $schema->string()->nullable()->description('Recommended next action text'),
            'reason' => $schema->string()->nullable()->description('Why this is the best next step'),
        ];
    }

    protected function run(Request $request): array
    {
        $leadId = isset($request['outreach_lead_id']) ? (int) $request['outreach_lead_id'] : null;
        $conversationId = isset($request['conversation_id']) ? (int) $request['conversation_id'] : null;

        if (! $leadId && ! $conversationId) {
            throw new \RuntimeException('Provide outreach_lead_id or conversation_id.');
        }

        $result = app(NextBestActionCommandCenterService::class)->stage(
            $this->context->user,
            $this->context->organizationId,
            $this->context->conversation,
            $leadId,
            $conversationId,
            trim((string) ($request['action'] ?? '')),
            $request['reason'] ?? null,
            $this->context->channel,
        );

        if ($result['blocked'] ?? false) {
            throw new \RuntimeException((string) ($result['message'] ?? 'Blocked'));
        }

        return [
            'approval_id' => $result['approval_id'],
            'plan' => $result['plan'],
            'card' => $result['card'],
            'cta' => $result['approval_id']
                ? 'User should Review & Launch to save on lead (LAUNCH '.$result['approval_id'].' on WhatsApp).'
                : ($result['message'] ?? 'Copilot mode: recommendation only.'),
        ];
    }
}

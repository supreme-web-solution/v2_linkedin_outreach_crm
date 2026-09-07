<?php

namespace App\Ai\Tools;

use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\PersonalizedMessageCommandCenterService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class DraftPersonalizedMessageTool extends GatedTool
{
    public function toolName(): string
    {
        return 'draft_personalized_message';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Prepare;
    }

    public function description(): Stringable|string
    {
        return 'Draft evidence-grounded outreach copy for a prospect. Does not send until Review & Launch saves it to the lead.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'outreach_lead_id' => $schema->integer()->nullable()->description('Outreach lead id'),
            'conversation_id' => $schema->integer()->nullable()->description('Unified Inbox conversation id (resolves lead)'),
            'channel' => $schema->string()->nullable()->description('Channel key: linkedin, email, whatsapp, etc.'),
            'action' => $schema->string()->nullable()->description('Outreach step action, default send_message'),
            'goal' => $schema->string()->nullable()->description('What the message should accomplish'),
            'notes' => $schema->string()->nullable()->description('Optional tone or angle guidance'),
        ];
    }

    protected function run(Request $request): array
    {
        $leadId = isset($request['outreach_lead_id']) ? (int) $request['outreach_lead_id'] : null;
        $conversationId = isset($request['conversation_id']) ? (int) $request['conversation_id'] : null;

        if (! $leadId && ! $conversationId) {
            throw new \RuntimeException('Provide outreach_lead_id or conversation_id.');
        }

        $result = app(PersonalizedMessageCommandCenterService::class)->stageDraft(
            $this->context->user,
            $this->context->organizationId,
            $this->context->conversation,
            $leadId,
            $conversationId,
            (string) ($request['channel'] ?? 'linkedin'),
            (string) ($request['action'] ?? 'send_message'),
            trim((string) ($request['goal'] ?? '')),
            $request['notes'] ?? null,
            $this->context->channel,
        );

        if ($result['blocked'] ?? false) {
            throw new \RuntimeException((string) ($result['message'] ?? 'Blocked'));
        }

        return [
            'approval_id' => $result['approval_id'],
            'plan' => $result['plan'],
            'evidence' => $result['plan']['evidence'] ?? [],
            'card' => $result['card'],
            'cta' => $result['approval_id']
                ? 'User should Review & Launch to save on lead (LAUNCH '.$result['approval_id'].' on WhatsApp).'
                : ($result['message'] ?? 'Copilot mode: recommendation only.'),
        ];
    }
}

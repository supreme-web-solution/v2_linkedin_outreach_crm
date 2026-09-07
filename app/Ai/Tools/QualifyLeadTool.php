<?php

namespace App\Ai\Tools;

use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\QualifyLeadCommandCenterService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class QualifyLeadTool extends GatedTool
{
    public function toolName(): string
    {
        return 'qualify_lead';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Prepare;
    }

    public function description(): Stringable|string
    {
        return 'Stage lead qualification (MQL/SQL/disqualified/nurture) on an outreach lead for Review & Launch.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'outreach_lead_id' => $schema->integer()->nullable(),
            'conversation_id' => $schema->integer()->nullable(),
            'stage' => $schema->string()->required()->description('mql, sql, qualified, disqualified, nurture, meeting_booked'),
            'score' => $schema->integer()->min(0)->max(100)->nullable(),
            'notes' => $schema->string()->nullable(),
        ];
    }

    protected function run(Request $request): array
    {
        $leadId = isset($request['outreach_lead_id']) ? (int) $request['outreach_lead_id'] : null;
        $conversationId = isset($request['conversation_id']) ? (int) $request['conversation_id'] : null;

        if (! $leadId && ! $conversationId) {
            throw new \RuntimeException('Provide outreach_lead_id or conversation_id.');
        }

        $result = app(QualifyLeadCommandCenterService::class)->stage(
            $this->context->user,
            $this->context->organizationId,
            $this->context->conversation,
            $leadId,
            $conversationId,
            (string) $request['stage'],
            isset($request['score']) ? (int) $request['score'] : null,
            $request['notes'] ?? null,
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
                ? 'Review & Launch to save qualification (LAUNCH '.$result['approval_id'].').'
                : ($result['message'] ?? 'Copilot mode.'),
        ];
    }
}

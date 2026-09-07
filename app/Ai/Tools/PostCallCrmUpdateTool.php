<?php

namespace App\Ai\Tools;

use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\PostCallCrmCommandCenterService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class PostCallCrmUpdateTool extends GatedTool
{
    public function toolName(): string
    {
        return 'post_call_crm_update';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Prepare;
    }

    public function description(): Stringable|string
    {
        return 'Stage a post-call CRM update (outcome, summary, next steps) for Review & Launch.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'call_id' => $schema->integer()->required(),
            'outcome' => $schema->string()->required()->description('completed, follow_up, not_interested, no_show, rescheduled, qualified'),
            'summary' => $schema->string()->nullable(),
            'next_steps' => $schema->string()->nullable(),
        ];
    }

    protected function run(Request $request): array
    {
        $result = app(PostCallCrmCommandCenterService::class)->stage(
            $this->context->user,
            $this->context->organizationId,
            $this->context->conversation,
            (int) $request['call_id'],
            (string) $request['outcome'],
            $request['summary'] ?? null,
            $request['next_steps'] ?? null,
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
                ? 'Review & Launch to save CRM update (LAUNCH '.$result['approval_id'].').'
                : ($result['message'] ?? 'Copilot mode.'),
        ];
    }
}

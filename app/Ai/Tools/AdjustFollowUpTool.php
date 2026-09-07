<?php

namespace App\Ai\Tools;

use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\FollowUpAdjustCommandCenterService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class AdjustFollowUpTool extends GatedTool
{
    public function toolName(): string
    {
        return 'adjust_follow_up';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Prepare;
    }

    public function description(): Stringable|string
    {
        return 'Suggest follow-up sequence timing changes for an outreach campaign. Applies on Review & Launch.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'campaign_id' => $schema->integer()->required()->description('Outreach campaign id'),
            'adjustment' => $schema->string()->required()->description('extend_waits, shorten_waits, or pause_on_reply'),
            'delta_days' => $schema->integer()->min(1)->max(14)->nullable()->description('Days to add/remove for wait steps'),
            'reason' => $schema->string()->nullable()->description('Why this adjustment helps'),
        ];
    }

    protected function run(Request $request): array
    {
        $result = app(FollowUpAdjustCommandCenterService::class)->stageAdjustment(
            $this->context->user,
            $this->context->organizationId,
            $this->context->conversation,
            (int) $request['campaign_id'],
            (string) $request['adjustment'],
            isset($request['delta_days']) ? (int) $request['delta_days'] : null,
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
                ? 'User should Review & Launch to apply (LAUNCH '.$result['approval_id'].' on WhatsApp).'
                : ($result['message'] ?? 'Copilot mode: recommendation only.'),
        ];
    }
}

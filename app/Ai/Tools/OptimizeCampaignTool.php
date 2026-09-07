<?php

namespace App\Ai\Tools;

use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\OptimizeCampaignCommandCenterService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class OptimizeCampaignTool extends GatedTool
{
    public function toolName(): string
    {
        return 'optimize_campaign';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Prepare;
    }

    public function description(): Stringable|string
    {
        return 'Analyze outreach campaign performance and stage optimization recommendations for Review & Launch.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'campaign_id' => $schema->integer()->required()->description('Outreach campaign id to optimize'),
        ];
    }

    protected function run(Request $request): array
    {
        $result = app(OptimizeCampaignCommandCenterService::class)->stage(
            $this->context->user,
            $this->context->organizationId,
            $this->context->conversation,
            (int) $request['campaign_id'],
            $this->context->channel,
        );

        if ($result['blocked'] ?? false) {
            throw new \RuntimeException((string) ($result['message'] ?? 'Blocked'));
        }

        return [
            'approval_id' => $result['approval_id'],
            'plan' => $result['plan'],
            'analysis' => $result['analysis'] ?? null,
            'card' => $result['card'],
            'cta' => $result['approval_id']
                ? 'User should Review & Launch to save recommendations (LAUNCH '.$result['approval_id'].' on WhatsApp).'
                : ($result['message'] ?? 'Copilot mode: recommendations only.'),
        ];
    }
}

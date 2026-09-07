<?php

namespace App\Ai\Tools;

use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\OutreachCampaignCommandService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class PauseOutreachCampaignTool extends GatedTool
{
    public function toolName(): string
    {
        return 'pause_outreach_campaign';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Execute;
    }

    public function description(): Stringable|string
    {
        return 'Pause one outreach campaign or all active outreach campaigns for the user.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'campaign_id' => $schema->integer()->nullable()->description('Omit to pause all active campaigns'),
            'pause_all' => $schema->boolean()->nullable(),
        ];
    }

    protected function run(Request $request): array
    {
        $pauseAll = (bool) ($request['pause_all'] ?? false);
        $campaignId = $request['campaign_id'] ?? null;

        if ($pauseAll) {
            $campaignId = null;
        }

        $result = app(OutreachCampaignCommandService::class)->pause(
            $this->context->user,
            $this->context->organizationId,
            $campaignId !== null ? (int) $campaignId : null,
        );

        return $result;
    }
}

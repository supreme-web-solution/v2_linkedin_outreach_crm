<?php

namespace App\Ai\Tools;

use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\OutreachCampaignCommandService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ActivateOutreachCampaignTool extends GatedTool
{
    public function toolName(): string
    {
        return 'activate_outreach_campaign';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Execute;
    }

    public function description(): Stringable|string
    {
        return 'Start (activate) a draft outreach campaign after lists are attached. Queues lead sync then begins the run.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'campaign_id' => $schema->integer()->required(),
        ];
    }

    protected function run(Request $request): array
    {
        $result = app(OutreachCampaignCommandService::class)->activate(
            $this->context->user,
            $this->context->organizationId,
            (int) $request['campaign_id'],
        );

        return $result;
    }
}

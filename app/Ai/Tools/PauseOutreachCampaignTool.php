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
            'campaign_id' => $schema->integer()->nullable()->description('Single campaign id'),
            'campaign_ids' => $schema->array()->nullable()->description('Bulk: pause several campaigns in one call'),
            'pause_all' => $schema->boolean()->nullable(),
        ];
    }

    protected function run(Request $request): array
    {
        $service = app(OutreachCampaignCommandService::class);
        $pauseAll = (bool) ($request['pause_all'] ?? false);
        $ids = [];
        if (is_array($request['campaign_ids'] ?? null)) {
            foreach ($request['campaign_ids'] as $id) {
                $n = (int) $id;
                if ($n > 0) {
                    $ids[] = $n;
                }
            }
        }
        if ($ids === [] && isset($request['campaign_id'])) {
            $n = (int) $request['campaign_id'];
            if ($n > 0) {
                $ids[] = $n;
            }
        }

        if ($pauseAll || ($ids === [] && ! isset($request['campaign_id']))) {
            return $service->pause(
                $this->context->user,
                $this->context->organizationId,
                null,
            );
        }

        if (count($ids) > 1) {
            return $service->pauseMany(
                $this->context->user,
                $this->context->organizationId,
                $ids,
            );
        }

        return $service->pause(
            $this->context->user,
            $this->context->organizationId,
            $ids[0] ?? null,
        );
    }
}

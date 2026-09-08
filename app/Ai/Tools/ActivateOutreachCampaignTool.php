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
        return 'Start (activate) one or many draft outreach campaigns after lists are attached. Queues lead sync then begins the run.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'campaign_id' => $schema->integer()->nullable()->description('Single campaign id'),
            'campaign_ids' => $schema->array()->nullable()->description('Bulk: activate several campaigns in one call'),
        ];
    }

    protected function run(Request $request): array
    {
        $service = app(OutreachCampaignCommandService::class);
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
        if ($ids === []) {
            throw new \InvalidArgumentException('Provide campaign_id or campaign_ids.');
        }

        if (count($ids) > 1) {
            return $service->activateMany(
                $this->context->user,
                $this->context->organizationId,
                $ids,
            );
        }

        return $service->activate(
            $this->context->user,
            $this->context->organizationId,
            $ids[0],
        );
    }
}

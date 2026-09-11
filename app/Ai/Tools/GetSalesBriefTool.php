<?php

namespace App\Ai\Tools;

use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\SalesBriefService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetSalesBriefTool extends GatedTool
{
    public function toolName(): string
    {
        return 'get_sales_brief';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Read;
    }

    public function description(): Stringable|string
    {
        return 'Daily/status snapshot: leads, campaigns, inbox, replies, pipeline. Use for "what do we have today", updates, and status — NOT for finding new prospects.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'period' => $schema->string()->enum(['today', 'week', 'all'])->nullable(),
        ];
    }

    protected function run(Request $request): array
    {
        $period = (string) ($request['period'] ?? 'all');
        $payload = app(SalesBriefService::class)->forUser(
            $this->context->user,
            $this->context->organizationId,
            in_array($period, ['today', 'week', 'all'], true) ? $period : 'all',
        );

        return [
            'period' => $period,
            'metrics' => $payload['metrics'],
            'acquisition_funnel' => $payload['acquisition_funnel'],
            'active_outreach' => $payload['active_outreach'],
            'brief' => $payload['brief'],
        ];
    }
}

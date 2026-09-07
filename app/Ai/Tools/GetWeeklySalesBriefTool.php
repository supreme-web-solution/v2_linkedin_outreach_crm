<?php

namespace App\Ai\Tools;

use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\WeeklySalesBriefService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetWeeklySalesBriefTool extends GatedTool
{
    public function toolName(): string
    {
        return 'get_weekly_sales_brief';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Read;
    }

    public function description(): Stringable|string
    {
        return 'Weekly sales manager snapshot: 7-day replies, hot leads, upcoming calls, active outreach.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function run(Request $request): array
    {
        return app(WeeklySalesBriefService::class)->forUser(
            $this->context->user,
            $this->context->organizationId,
        );
    }
}

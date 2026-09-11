<?php

namespace App\Ai\Tools;

use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\AiActivityLogService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetActivityTool extends GatedTool
{
    public function toolName(): string
    {
        return 'get_activity';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Read;
    }

    public function description(): Stringable|string
    {
        return 'Get factual AI activity history (what Soci created/updated/deleted/sent) filtered by action/entity/time window.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->nullable()->description('Optional activity action filter'),
            'entity_type' => $schema->string()->nullable()->description('Optional entity type filter'),
            'created_after' => $schema->string()->nullable()->description('ISO datetime lower bound'),
            'created_before' => $schema->string()->nullable()->description('ISO datetime upper bound'),
            'today' => $schema->boolean()->nullable()->description('true => today window in app timezone'),
            'limit' => $schema->integer()->min(1)->max(200)->nullable(),
        ];
    }

    protected function run(Request $request): array
    {
        $after = isset($request['created_after']) ? (string) $request['created_after'] : null;
        $before = isset($request['created_before']) ? (string) $request['created_before'] : null;
        if ((bool) ($request['today'] ?? false)) {
            $after = now()->startOfDay()->toIso8601String();
            $before = now()->endOfDay()->toIso8601String();
        }

        $rows = app(AiActivityLogService::class)->queryForUser(
            $this->context->user,
            $this->context->organizationId,
            isset($request['action']) ? (string) $request['action'] : null,
            isset($request['entity_type']) ? (string) $request['entity_type'] : null,
            $after,
            $before,
            (int) ($request['limit'] ?? 50),
        );

        return [
            'count' => count($rows),
            'activity' => $rows,
        ];
    }
}

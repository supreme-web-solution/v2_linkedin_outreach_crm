<?php

namespace App\Ai\Tools;

use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\AiActivityLogService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchActivityTool extends GatedTool
{
    public function toolName(): string
    {
        return 'search_activity';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Read;
    }

    public function description(): Stringable|string
    {
        return 'Search factual AI activity logs by keyword/action/entity/time for answering "what did you do?" questions.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'keyword' => $schema->string()->nullable()->description('Keyword in action/tool/entity fields'),
            'action' => $schema->string()->nullable(),
            'entity_type' => $schema->string()->nullable(),
            'created_after' => $schema->string()->nullable(),
            'created_before' => $schema->string()->nullable(),
            'limit' => $schema->integer()->min(1)->max(200)->nullable(),
        ];
    }

    protected function run(Request $request): array
    {
        $rows = app(AiActivityLogService::class)->queryForUser(
            $this->context->user,
            $this->context->organizationId,
            isset($request['action']) ? (string) $request['action'] : null,
            isset($request['entity_type']) ? (string) $request['entity_type'] : null,
            isset($request['created_after']) ? (string) $request['created_after'] : null,
            isset($request['created_before']) ? (string) $request['created_before'] : null,
            (int) ($request['limit'] ?? 50),
            isset($request['keyword']) ? (string) $request['keyword'] : null,
        );

        return ['count' => count($rows), 'activity' => $rows];
    }
}

<?php

namespace App\Ai\Tools;

use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\ContentPostCommandCenterService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListContentPostsTool extends GatedTool
{
    public function toolName(): string
    {
        return 'list_content_posts';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Read;
    }

    public function description(): Stringable|string
    {
        return 'List LinkedIn Content posts (draft, scheduled, published). Use before rescheduling or editing schedules.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum(['draft', 'scheduled', 'published', 'failed'])->nullable(),
            'scheduled_day' => $schema->string()->nullable()->description('Filter by schedule day, e.g. tomorrow, Wednesday, 2026-09-08'),
            'limit' => $schema->integer()->nullable(),
        ];
    }

    protected function run(Request $request): array
    {
        $posts = app(ContentPostCommandCenterService::class)->listPosts(
            user: $this->context->user,
            organizationId: $this->context->organizationId,
            status: isset($request['status']) ? (string) $request['status'] : null,
            scheduledDay: isset($request['scheduled_day']) ? (string) $request['scheduled_day'] : null,
            limit: (int) ($request['limit'] ?? 20),
        );

        return [
            'count' => count($posts),
            'posts' => $posts,
            'content_url' => url('/content'),
        ];
    }
}

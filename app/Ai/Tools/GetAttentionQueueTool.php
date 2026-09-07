<?php

namespace App\Ai\Tools;

use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\AttentionQueueService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetAttentionQueueTool extends GatedTool
{
    public function toolName(): string
    {
        return 'get_attention_queue';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Read;
    }

    public function description(): Stringable|string
    {
        return 'List conversations that need the user\'s attention (unread inbox replies, hot leads, pending approvals).';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->min(1)->max(50)->nullable(),
        ];
    }

    protected function run(Request $request): array
    {
        $limit = (int) ($request['limit'] ?? 10);

        return app(AttentionQueueService::class)->forUser(
            $this->context->user,
            $this->context->organizationId,
            $limit,
        );
    }
}

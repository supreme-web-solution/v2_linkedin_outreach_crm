<?php

namespace App\Ai\Tools;

use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\NurtureQueueService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetNurtureDueQueueTool extends GatedTool
{
    public function toolName(): string
    {
        return 'get_nurture_due_queue';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Read;
    }

    public function description(): Stringable|string
    {
        return 'List nurture leads due or overdue for follow-up ("maybe later" prospects). Use when the user asks who is due for nurture follow-up.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->min(1)->max(50)->nullable(),
        ];
    }

    protected function run(Request $request): array
    {
        $limit = (int) ($request['limit'] ?? 20);

        return app(NurtureQueueService::class)->dueForFollowUp($this->context->user, $limit);
    }
}

<?php

namespace App\Ai\Tools;

use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\ExecuteSalesPlanCommandCenterService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class LetAiExecuteTool extends GatedTool
{
    public function toolName(): string
    {
        return 'let_ai_execute';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Prepare;
    }

    public function description(): Stringable|string
    {
        return 'Build one sales manager execution plan: pause struggling campaigns and send follow-ups to hot inbox threads. User Launches once to run all steps.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'max_pause' => $schema->integer()->min(0)->max(10)->nullable()->description('Max campaigns to pause (default 3)'),
            'max_follow_ups' => $schema->integer()->min(0)->max(15)->nullable()->description('Max inbox follow-ups to send (default 7)'),
        ];
    }

    protected function run(Request $request): array
    {
        return app(ExecuteSalesPlanCommandCenterService::class)->stage(
            user: $this->context->user,
            organizationId: $this->context->organizationId,
            conversation: $this->context->conversation,
            surface: $this->context->channel,
            maxPause: isset($request['max_pause']) ? (int) $request['max_pause'] : null,
            maxFollowUps: isset($request['max_follow_ups']) ? (int) $request['max_follow_ups'] : null,
        );
    }
}

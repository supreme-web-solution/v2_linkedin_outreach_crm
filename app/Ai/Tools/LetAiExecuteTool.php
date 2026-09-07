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
        return 'Build one sales manager execution plan: follow up hot inbox, nurture cold replies, pause struggling campaigns, scale winners (~20%), activate ready drafts, and shift channel mix. User Launches once to run all steps.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'max_pause' => $schema->integer()->min(0)->max(10)->nullable()->description('Max campaigns to pause (default 3)'),
            'max_follow_ups' => $schema->integer()->min(0)->max(15)->nullable()->description('Max inbox follow-ups to send (default 7)'),
            'max_nurture' => $schema->integer()->min(0)->max(10)->nullable()->description('Max leads to move to nurture (default 3)'),
            'max_scale' => $schema->integer()->min(0)->max(5)->nullable()->description('Max winning campaigns to scale ~20% (default 2)'),
            'max_activate' => $schema->integer()->min(0)->max(5)->nullable()->description('Max ready drafts to activate (default 2)'),
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
            maxNurture: isset($request['max_nurture']) ? (int) $request['max_nurture'] : null,
            maxScale: isset($request['max_scale']) ? (int) $request['max_scale'] : null,
            maxActivate: isset($request['max_activate']) ? (int) $request['max_activate'] : null,
        );
    }
}

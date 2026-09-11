<?php

namespace App\Ai\Tools;

use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\AcquisitionExperimentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class StartAcquisitionExperimentTool extends GatedTool
{
    public function toolName(): string
    {
        return 'start_acquisition_experiment';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Prepare;
    }

    public function description(): Stringable|string
    {
        return 'Start a validation experiment (e.g. SociFusion acquiring SociFusion customers). Tracks funnel on dashboard.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'niche' => $schema->string()->required()->description('Who to target, e.g. marketing agencies 2-20 employees'),
            'target_prospects' => $schema->integer()->min(50)->max(2000)->nullable()->description('Target list size, default 500'),
            'message_angle' => $schema->string()->nullable()->description('Optional first-message angle: problem, acquisition, competitive'),
        ];
    }

    protected function run(Request $request): array
    {
        $experiment = app(AcquisitionExperimentService::class)->startExperiment(
            $this->context->user,
            $this->context->organizationId,
            (string) $request['niche'],
            isset($request['target_prospects']) ? (int) $request['target_prospects'] : 500,
            isset($request['message_angle']) ? (string) $request['message_angle'] : null,
        );

        return [
            'experiment' => $experiment,
            'message' => 'Validation experiment started. Dashboard now tracks your acquisition funnel. '
                .'Next: discover_prospects platform=all with target_count '.$experiment['target_prospects'].' for niche: '.$experiment['niche'],
        ];
    }
}

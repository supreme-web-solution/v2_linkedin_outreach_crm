<?php

namespace App\Ai\Tools;

use App\Models\AiWorkflowRun;
use App\V2\Ai\Enums\AiToolPermission;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetWorkflowRunTool extends GatedTool
{
    public function toolName(): string
    {
        return 'get_workflow_run';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Read;
    }

    public function description(): Stringable|string
    {
        return 'Get durable workflow run status and steps for compound AI operations.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'workflow_run_id' => $schema->integer()->required()->min(1),
        ];
    }

    protected function run(Request $request): array
    {
        $run = AiWorkflowRun::query()
            ->where('id', (int) $request['workflow_run_id'])
            ->where('user_id', $this->context->user->id)
            ->where('organization_id', $this->context->organizationId)
            ->with('steps')
            ->first();

        if (! $run) {
            return ['ok' => false, 'error' => 'Workflow run not found.'];
        }

        return [
            'ok' => true,
            'workflow' => [
                'id' => $run->id,
                'goal' => $run->goal,
                'status' => $run->status,
                'approval_status' => $run->approval_status,
                'current_step' => $run->current_step,
                'scheduled_at' => $run->scheduled_at?->toIso8601String(),
                'started_at' => $run->started_at?->toIso8601String(),
                'completed_at' => $run->completed_at?->toIso8601String(),
                'failed_at' => $run->failed_at?->toIso8601String(),
                'plan' => $run->plan,
                'steps' => $run->steps->map(fn ($step) => [
                    'id' => $step->id,
                    'step_key' => $step->step_key,
                    'sequence' => $step->sequence,
                    'tool_name' => $step->tool_name,
                    'status' => $step->status,
                    'approval_required' => $step->approval_required,
                    'approval_status' => $step->approval_status,
                ])->values()->all(),
            ],
        ];
    }
}

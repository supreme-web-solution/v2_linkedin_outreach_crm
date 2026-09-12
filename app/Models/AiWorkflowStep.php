<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiWorkflowStep extends Model
{
    protected $table = 'ai_workflow_steps';

    protected $fillable = [
        'workflow_run_id',
        'step_key',
        'sequence',
        'tool_name',
        'arguments',
        'status',
        'depends_on',
        'approval_required',
        'approval_status',
        'started_at',
        'completed_at',
        'result',
        'error',
        'retry_count',
    ];

    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'depends_on' => 'array',
            'result' => 'array',
            'approval_required' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function workflowRun(): BelongsTo
    {
        return $this->belongsTo(AiWorkflowRun::class, 'workflow_run_id');
    }
}

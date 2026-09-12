<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiWorkflowRun extends Model
{
    protected $table = 'ai_workflow_runs';

    protected $fillable = [
        'organization_id',
        'user_id',
        'conversation_id',
        'agent',
        'goal',
        'status',
        'plan',
        'current_step',
        'approval_status',
        'approval_scope',
        'scope_hash',
        'scheduled_at',
        'started_at',
        'completed_at',
        'failed_at',
        'result',
        'error',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'plan' => 'array',
            'result' => 'array',
            'meta' => 'array',
            'approval_scope' => 'array',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(AiWorkflowStep::class, 'workflow_run_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

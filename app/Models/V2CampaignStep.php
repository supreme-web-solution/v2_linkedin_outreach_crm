<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class V2CampaignStep extends Model
{
    protected $fillable = [
        'campaign_run_id',
        'step_key',
        'step_type',
        'status',
        'executed_at',
        'error_message',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'executed_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(V2CampaignRun::class, 'campaign_run_id');
    }
}

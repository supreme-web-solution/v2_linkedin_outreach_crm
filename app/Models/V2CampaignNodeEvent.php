<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class V2CampaignNodeEvent extends Model
{
    protected $fillable = [
        'campaign_id',
        'campaign_lead_id',
        'campaign_run_id',
        'node_key',
        'node_label',
        'step_type',
        'status',
        'message',
        'payload',
        'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'executed_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(V2Campaign::class, 'campaign_id');
    }

    public function campaignLead(): BelongsTo
    {
        return $this->belongsTo(V2CampaignLead::class, 'campaign_lead_id');
    }

    public function campaignRun(): BelongsTo
    {
        return $this->belongsTo(V2CampaignRun::class, 'campaign_run_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class V2CampaignLeadProgress extends Model
{
    protected $fillable = [
        'campaign_id', 'campaign_lead_id', 'current_node_key',
        'next_node_key', 'acceptance_status', 'run_status',
        'completed_keys', 'meta', 'next_run_at',
    ];

    protected function casts(): array
    {
        return [
            'acceptance_status' => 'boolean',
            'completed_keys'    => 'array',
            'meta'              => 'array',
            'next_run_at'       => 'datetime',
        ];
    }

    public function campaignLead(): BelongsTo
    {
        return $this->belongsTo(V2CampaignLead::class, 'campaign_lead_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(V2Campaign::class, 'campaign_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class V2OutreachLeadProgress extends Model
{
    protected $table = 'v2_outreach_lead_progress';

    protected $fillable = [
        'outreach_campaign_id',
        'outreach_lead_id',
        'current_node_key',
        'next_node_key',
        'acceptance_status',
        'run_status',
        'completed_keys',
        'channel_state',
        'meta',
        'next_run_at',
    ];

    protected function casts(): array
    {
        return [
            'completed_keys' => 'array',
            'channel_state' => 'array',
            'meta' => 'array',
            'next_run_at' => 'datetime',
            'acceptance_status' => 'boolean',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(V2OutreachCampaign::class, 'outreach_campaign_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(V2OutreachLead::class, 'outreach_lead_id');
    }
}

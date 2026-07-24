<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class V2OutreachNodeEvent extends Model
{
    protected $fillable = [
        'outreach_campaign_id',
        'outreach_lead_id',
        'outreach_run_id',
        'node_key',
        'channel',
        'action',
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
        return $this->belongsTo(V2OutreachCampaign::class, 'outreach_campaign_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(V2OutreachLead::class, 'outreach_lead_id');
    }
}

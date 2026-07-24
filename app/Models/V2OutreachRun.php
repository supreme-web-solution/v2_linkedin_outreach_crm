<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class V2OutreachRun extends Model
{
    protected $fillable = [
        'user_id',
        'outreach_campaign_id',
        'status',
        'started_at',
        'finished_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(V2OutreachCampaign::class, 'outreach_campaign_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class V2CallCampaignMessage extends Model
{
    protected $fillable = [
        'campaign_id',
        'recipient_id',
        'message',
        'status',
        'scheduled_at',
        'sent_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(V2CallCampaign::class, 'campaign_id');
    }
}

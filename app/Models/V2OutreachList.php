<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class V2OutreachList extends Model
{
    protected $fillable = [
        'outreach_campaign_id',
        'list_hash',
        'list_src',
        'list_name',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(V2OutreachCampaign::class, 'outreach_campaign_id');
    }
}

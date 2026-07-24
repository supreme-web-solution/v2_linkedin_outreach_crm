<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class V2CampaignList extends Model
{
    protected $fillable = [
        'campaign_id',
        'list_hash',
        'list_src',
        'list_name',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(V2Campaign::class, 'campaign_id');
    }
}

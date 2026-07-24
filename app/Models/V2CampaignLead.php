<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class V2CampaignLead extends Model
{
    protected $fillable = [
        'campaign_id', 'lead_id', 'provider_profile_id',
        'full_name', 'headline', 'profile_url', 'status',
        'source_list_src', 'source_record_id', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(V2Campaign::class, 'campaign_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(V2Lead::class, 'lead_id');
    }

    public function progress(): HasOne
    {
        return $this->hasOne(V2CampaignLeadProgress::class, 'campaign_lead_id');
    }
}

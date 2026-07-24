<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class V2OutreachLead extends Model
{
    protected $fillable = [
        'outreach_campaign_id',
        'source_list_src',
        'source_record_id',
        'provider_profile_id',
        'email',
        'phone',
        'full_name',
        'headline',
        'profile_url',
        'status',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(V2OutreachCampaign::class, 'outreach_campaign_id');
    }

    public function progress(): HasOne
    {
        return $this->hasOne(V2OutreachLeadProgress::class, 'outreach_lead_id');
    }
}

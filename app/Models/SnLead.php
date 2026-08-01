<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SnLead extends Model
{
    protected $fillable = [
        'first_name',
        'last_name',
        'headline',
        'email',
        'email_fetch_status',
        'email_fetch_attempted_at',
        'phone',
        'phone_fetch_attempted_at',
        'phone_fetch_status',
        'whatsapp_provider_id',
        'instagram_handle',
        'instagram_provider_id',
        'telegram_handle',
        'telegram_provider_id',
        'twitter_handle',
        'twitter_provider_id',
        'lid',
        'sn_lid',
        'picture',
        'geolocation',
        'degree',
        'object_urn',
        'jobs',
        'sn_list_id',
        'outreach_status',
    ];

    public function company(): HasOne
    {
        return $this->hasOne(SnLeadsCompany::class, 'sn_lead_id');
    }
}

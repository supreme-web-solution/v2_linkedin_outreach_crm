<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class V2OutreachImportLead extends Model
{
    protected $fillable = [
        'import_list_id',
        'full_name',
        'email',
        'phone',
        'linkedin_id',
        'profile_url',
        'whatsapp_provider_id',
        'whatsapp_verify_status',
        'instagram_handle',
        'instagram_provider_id',
        'telegram_handle',
        'telegram_provider_id',
        'twitter_handle',
        'twitter_provider_id',
    ];

    public function importList(): BelongsTo
    {
        return $this->belongsTo(V2OutreachImportList::class, 'import_list_id');
    }
}

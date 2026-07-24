<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class V2LeadContactOverlay extends Model
{
    protected $fillable = [
        'user_id',
        'linkedin_key',
        'email',
        'phone',
        'whatsapp_provider_id',
        'instagram_handle',
        'instagram_provider_id',
        'telegram_handle',
        'telegram_provider_id',
        'twitter_handle',
        'twitter_provider_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

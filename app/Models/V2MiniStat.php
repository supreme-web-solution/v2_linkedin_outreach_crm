<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class V2MiniStat extends Model
{
    protected $fillable = [
        'user_id',
        'organization_id',
        'connections',
        'sent_invites',
        'profile_views',
        'profile_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

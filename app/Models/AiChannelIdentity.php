<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiChannelIdentity extends Model
{
    protected $table = 'ai_channel_identities';

    protected $fillable = [
        'organization_id',
        'user_id',
        'channel',
        'external_id',
        'status',
        'verified_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(V2Organization::class, 'organization_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

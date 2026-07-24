<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class V2Reminder extends Model
{
    protected $fillable = [
        'user_id',
        'organization_id',
        'call_id',
        'status',
        'message',
        'send_at',
        'sent_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'send_at' => 'datetime',
            'sent_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(V2Call::class, 'call_id');
    }
}

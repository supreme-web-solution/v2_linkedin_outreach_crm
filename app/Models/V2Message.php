<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class V2Message extends Model
{
    protected $fillable = [
        'conversation_id',
        'provider_message_id',
        'direction',
        'body',
        'attachments',
        'sent_at',
        'received_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(V2Conversation::class, 'conversation_id');
    }
}

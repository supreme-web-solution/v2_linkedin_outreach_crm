<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiZernioWebhookEvent extends Model
{
    protected $table = 'ai_zernio_webhook_events';

    protected $fillable = [
        'event_id',
        'event',
        'status',
        'meta',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}

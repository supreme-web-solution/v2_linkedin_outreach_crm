<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class V2EspDelivery extends Model
{
    protected $fillable = [
        'organization_id',
        'user_id',
        'esp_integration_id',
        'lead_id',
        'provider',
        'recipient',
        'status',
        'external_message_id',
        'subject',
        'body_preview',
        'events',
        'meta',
        'sent_at',
        'delivered_at',
        'opened_at',
        'clicked_at',
        'bounced_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'meta' => 'array',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'opened_at' => 'datetime',
            'clicked_at' => 'datetime',
            'bounced_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(V2EspIntegration::class, 'esp_integration_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(V2Lead::class, 'lead_id');
    }
}

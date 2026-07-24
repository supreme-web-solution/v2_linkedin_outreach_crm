<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class V2Call extends Model
{
    protected $fillable = [
        'user_id',
        'organization_id',
        'conversation_id',
        'connection_id',
        'prospect_name',
        'prospect_headline',
        'lead_id',
        'status',
        'pending_message',
        'conversation_history',
        'ai_analysis',
        'scheduled_send_at',
        'scheduled_call_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'conversation_history' => 'array',
            'ai_analysis' => 'array',
            'scheduled_send_at' => 'datetime',
            'scheduled_call_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(V2Conversation::class, 'conversation_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(V2Lead::class, 'lead_id');
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(V2Reminder::class, 'call_id');
    }
}

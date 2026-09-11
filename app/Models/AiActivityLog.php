<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiActivityLog extends Model
{
    protected $table = 'ai_activity_logs';

    protected $fillable = [
        'organization_id',
        'user_id',
        'conversation_id',
        'tool',
        'action',
        'entity_type',
        'entity_id',
        'payload',
        'trigger_text',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiErrorLog extends Model
{
    protected $table = 'ai_error_logs';

    protected $fillable = [
        'organization_id',
        'user_id',
        'conversation_id',
        'source',
        'channel',
        'exception_class',
        'message',
        'user_message',
        'context',
        'trace',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(V2Organization::class, 'organization_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }
}

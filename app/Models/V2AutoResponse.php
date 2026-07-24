<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class V2AutoResponse extends Model
{
    protected $fillable = [
        'user_id',
        'organization_id',
        'message_type',
        'message_keywords',
        'message_body',
        'attachments',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'enabled' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

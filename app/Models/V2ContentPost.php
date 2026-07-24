<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class V2ContentPost extends Model
{
    protected $fillable = [
        'user_id',
        'organization_id',
        'provider',
        'content',
        'status',
        'scheduled_at',
        'published_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
            'meta' => 'array',
        ];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class V2InspirationPost extends Model
{
    protected $fillable = [
        'user_id',
        'organization_id',
        'source',
        'post_id',
        'content',
        'is_favorite',
        'category',
        'engagement',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'is_favorite' => 'boolean',
            'engagement' => 'integer',
        ];
    }
}

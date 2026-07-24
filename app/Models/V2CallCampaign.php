<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class V2CallCampaign extends Model
{
    protected $fillable = [
        'user_id',
        'organization_id',
        'name',
        'status',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }
}

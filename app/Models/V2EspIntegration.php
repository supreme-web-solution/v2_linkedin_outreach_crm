<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class V2EspIntegration extends Model
{
    protected $fillable = [
        'user_id',
        'organization_id',
        'provider',
        'config',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'enabled' => 'boolean',
        ];
    }
}

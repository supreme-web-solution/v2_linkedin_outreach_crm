<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class V2OrganizationUser extends Model
{
    protected $table = 'v2_organization_user';

    protected $fillable = [
        'organization_id',
        'user_id',
        'role',
        'capabilities',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(V2Organization::class, 'organization_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

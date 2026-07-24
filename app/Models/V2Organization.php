<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class V2Organization extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'status',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'v2_organization_user', 'organization_id', 'user_id')
            ->withPivot(['role', 'capabilities', 'status'])
            ->withTimestamps();
    }
}

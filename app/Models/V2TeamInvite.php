<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class V2TeamInvite extends Model
{
    protected $fillable = [
        'organization_id',
        'inviter_user_id',
        'invitee_user_id',
        'invitee_email',
        'role',
        'capabilities',
        'status',
        'token',
        'expires_at',
        'accepted_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(V2Organization::class, 'organization_id');
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_user_id');
    }

    public function invitee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invitee_user_id');
    }
}

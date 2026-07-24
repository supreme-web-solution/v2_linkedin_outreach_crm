<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Integration extends Model
{
    protected $fillable = [
        'oauth_provider',
        'oauth_uid',
        'first_name',
        'last_name',
        'email',
        'picture',
        'access_token',
        'refresh_token',
        'expires_in',
        'refresh_token_expires_in',
        'connected_status',
        'user_id',
        'linkedin_session_cookie',
        'linkedin_user_agent',
        'linkedin_session_verified_at',
    ];

    protected $casts = [
        'linkedin_session_cookie' => 'encrypted',
        'linkedin_user_agent' => 'encrypted',
        'linkedin_session_verified_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }
}

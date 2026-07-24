<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class V2Lead extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'provider_profile_id',
        'public_identifier',
        'full_name',
        'headline',
        'company_name',
        'location',
        'email',
        'profile_data',
    ];

    protected function casts(): array
    {
        return [
            'profile_data' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(V2LeadSource::class, 'lead_id');
    }
}

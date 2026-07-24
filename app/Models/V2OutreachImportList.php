<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class V2OutreachImportList extends Model
{
    protected $fillable = [
        'user_id',
        'organization_id',
        'list_hash',
        'name',
        'lead_count',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(V2OutreachImportLead::class, 'import_list_id');
    }
}

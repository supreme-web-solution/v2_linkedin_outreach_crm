<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class V2LeadSource extends Model
{
    protected $fillable = [
        'lead_id',
        'source_type',
        'source_external_id',
        'source_payload',
    ];

    protected function casts(): array
    {
        return [
            'source_payload' => 'array',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(V2Lead::class, 'lead_id');
    }
}

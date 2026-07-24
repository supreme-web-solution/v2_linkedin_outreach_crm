<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class V2ProductTransaction extends Model
{
    protected $table = 'v2_product_transactions';

    protected $fillable = [
        'user_id',
        'product_id',
        'transaction_id',
        'transaction_type',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

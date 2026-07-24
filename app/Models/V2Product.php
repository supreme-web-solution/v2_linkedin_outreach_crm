<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class V2Product extends Model
{
    protected $table = 'v2_products';

    protected $fillable = [
        'product_id',
        'name',
        'entitlements',
    ];

    protected function casts(): array
    {
        return [
            'entitlements' => 'array',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(V2ProductTransaction::class, 'product_id', 'product_id');
    }
}

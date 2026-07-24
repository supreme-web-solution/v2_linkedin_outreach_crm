<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Audience extends Model
{
    protected $fillable = [
        'audience_name',
        'audience_id',
        'audience_type',
        'user_id',
        'tag',
        'source',
        'source_meta'
    ];
}

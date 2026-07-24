<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiContent extends Model
{
    protected $fillable = [
        'title',
        'contents',
        'word_counts',
        'ai_type',
        'language',
        'idea',
        'write_style',
        'connection_message_type',
        'connection_message_location',
        'connection_message_industry',
        'connection_message_jobtitle',
        'user_id',
    ];

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }
}

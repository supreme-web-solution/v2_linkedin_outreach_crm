<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SnLeadList extends Model
{
    protected $table = 'sn_leads_lists';
    
    protected $fillable = [
        'name',
        'list_hash',
        'user_id'
    ];
}

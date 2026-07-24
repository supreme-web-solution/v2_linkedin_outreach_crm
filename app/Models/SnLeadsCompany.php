<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SnLeadsCompany extends Model
{
    protected $fillable = [
        'sn_lead_id',
        'company_name',
        'company_specialities',
        'company_tagline',
        'company_description',
        'company_website',
        'company_phone',
        'company_staff_range',
        'company_staff_count',
        'company_headquaters',
        'company_revenue',
        'company_industries',
        'company_logo',
        'company_founded',
        'company_lid'
    ];
}

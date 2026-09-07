<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiEmployeeSetting extends Model
{
    protected $table = 'ai_employee_settings';

    protected $fillable = [
        'organization_id',
        'user_id',
        'enabled',
        'kill_switch',
        'autonomy_level',
        'employee_name',
        'allowed_execute_tools',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'kill_switch' => 'boolean',
            'autonomy_level' => 'integer',
            'allowed_execute_tools' => 'array',
            'meta' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(V2Organization::class, 'organization_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

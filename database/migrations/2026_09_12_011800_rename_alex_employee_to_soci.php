<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('ai_employee_settings')
            ->whereRaw('LOWER(TRIM(employee_name)) = ?', ['alex'])
            ->update(['employee_name' => 'Soci']);
    }

    public function down(): void
    {
        // Legacy rename — no rollback.
    }
};

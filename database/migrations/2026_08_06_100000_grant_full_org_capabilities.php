<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('v2_organization_user')
            ->where('status', 'active')
            ->update(['capabilities' => json_encode(['*'])]);
    }

    public function down(): void
    {
        // Capabilities were incorrect before this migration; no safe rollback.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sn_leads', function (Blueprint $table) {
            if (! Schema::hasColumn('sn_leads', 'outreach_status')) {
                $table->string('outreach_status', 30)->default('new')->after('degree');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sn_leads', function (Blueprint $table) {
            if (Schema::hasColumn('sn_leads', 'outreach_status')) {
                $table->dropColumn('outreach_status');
            }
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_outreach_import_leads', function (Blueprint $table) {
            $table->string('whatsapp_verify_status', 32)->nullable()->after('whatsapp_provider_id');
        });
    }

    public function down(): void
    {
        Schema::table('v2_outreach_import_leads', function (Blueprint $table) {
            $table->dropColumn('whatsapp_verify_status');
        });
    }
};

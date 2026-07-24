<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_calls', function (Blueprint $table) {
            $table->string('prospect_name', 191)->nullable()->after('connection_id');
            $table->string('prospect_headline', 255)->nullable()->after('prospect_name');
            $table->foreignId('lead_id')->nullable()->after('prospect_headline')->constrained('v2_leads')->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->json('call_settings')->nullable()->after('current_organization_id');
        });
    }

    public function down(): void
    {
        Schema::table('v2_calls', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lead_id');
            $table->dropColumn(['prospect_name', 'prospect_headline']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('call_settings');
        });
    }
};

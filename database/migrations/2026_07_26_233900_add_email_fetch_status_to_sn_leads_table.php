<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sn_leads', function (Blueprint $table) {
            $table->string('email_fetch_status', 20)->nullable()->after('email');
            $table->timestamp('email_fetch_attempted_at')->nullable()->after('email_fetch_status');
        });
    }

    public function down(): void
    {
        Schema::table('sn_leads', function (Blueprint $table) {
            $table->dropColumn(['email_fetch_status', 'email_fetch_attempted_at']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_action_logs', function (Blueprint $table) {
            $table->timestamp('undone_at')->nullable()->after('duration_ms');
            $table->json('undo_result')->nullable()->after('undone_at');
        });
    }

    public function down(): void
    {
        Schema::table('ai_action_logs', function (Blueprint $table) {
            $table->dropColumn(['undone_at', 'undo_result']);
        });
    }
};

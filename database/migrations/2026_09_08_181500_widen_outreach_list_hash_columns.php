<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_outreach_lists', function (Blueprint $table) {
            $table->string('list_hash', 191)->change();
        });

        if (Schema::hasTable('v2_campaign_lists')) {
            Schema::table('v2_campaign_lists', function (Blueprint $table) {
                $table->string('list_hash', 191)->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('v2_outreach_lists', function (Blueprint $table) {
            $table->string('list_hash', 50)->change();
        });

        if (Schema::hasTable('v2_campaign_lists')) {
            Schema::table('v2_campaign_lists', function (Blueprint $table) {
                $table->string('list_hash', 50)->change();
            });
        }
    }
};

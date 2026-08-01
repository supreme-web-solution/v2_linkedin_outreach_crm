<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Search persist uses string list hashes like "search-1-eleazar".
 * Production still had bigint list_hash / sn_list_id from the original SN schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sn_leads_lists')) {
            Schema::table('sn_leads_lists', function (Blueprint $table) {
                $table->string('list_hash', 64)->change();
            });
        }

        if (Schema::hasTable('sn_leads')) {
            Schema::table('sn_leads', function (Blueprint $table) {
                $table->string('sn_list_id', 64)->change();
            });
        }
    }

    public function down(): void
    {
        // Do not revert: string hashes like "search-1-eleazar" cannot cast back to bigint.
    }
};

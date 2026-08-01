<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v2_campaign_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('v2_campaigns')->cascadeOnDelete();
            $table->string('list_hash', 50);
            $table->string('list_src', 10);
            $table->string('list_name')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'list_hash', 'list_src'], 'v2_campaign_lists_unique');
        });

        Schema::table('v2_campaign_leads', function (Blueprint $table) {
            $table->string('source_list_src', 10)->nullable()->after('profile_url');
            $table->unsignedBigInteger('source_record_id')->nullable()->after('source_list_src');
            $table->json('meta')->nullable()->after('source_record_id');

            $table->unique(['campaign_id', 'source_list_src', 'source_record_id'], 'v2_campaign_leads_source_unique');
        });
    }

    public function down(): void
    {
        Schema::table('v2_campaign_leads', function (Blueprint $table) {
            $table->dropUnique('v2_campaign_leads_source_unique');
            $table->dropColumn(['source_list_src', 'source_record_id', 'meta']);
        });

        Schema::dropIfExists('v2_campaign_lists');
    }
};

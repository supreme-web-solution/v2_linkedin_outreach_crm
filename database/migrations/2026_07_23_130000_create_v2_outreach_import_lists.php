<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v2_outreach_import_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->string('list_hash', 64)->unique();
            $table->string('name', 191);
            $table->unsignedInteger('lead_count')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::create('v2_outreach_import_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_list_id')->constrained('v2_outreach_import_lists')->cascadeOnDelete();
            $table->string('full_name', 191)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('linkedin_id', 191)->nullable();
            $table->string('profile_url', 512)->nullable();
            $table->string('whatsapp_provider_id', 191)->nullable();
            $table->string('instagram_handle', 100)->nullable();
            $table->string('instagram_provider_id', 191)->nullable();
            $table->string('telegram_handle', 100)->nullable();
            $table->string('telegram_provider_id', 191)->nullable();
            $table->string('twitter_handle', 100)->nullable();
            $table->string('twitter_provider_id', 191)->nullable();
            $table->timestamps();

            $table->index(['import_list_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_outreach_import_leads');
        Schema::dropIfExists('v2_outreach_import_lists');
    }
};

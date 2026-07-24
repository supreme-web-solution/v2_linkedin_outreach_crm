<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audience_lists', function (Blueprint $table) {
            $table->string('con_phone', 50)->nullable()->after('con_email');
            $table->timestamp('phone_fetch_attempted_at')->nullable()->after('email_fetch_status');
            $table->string('phone_fetch_status', 20)->nullable()->after('phone_fetch_attempted_at');
            $table->string('whatsapp_provider_id', 191)->nullable()->after('phone_fetch_status');
        });

        Schema::table('sn_leads', function (Blueprint $table) {
            $table->string('phone', 50)->nullable()->after('email');
            $table->timestamp('phone_fetch_attempted_at')->nullable()->after('phone');
            $table->string('phone_fetch_status', 20)->nullable()->after('phone_fetch_attempted_at');
            $table->string('whatsapp_provider_id', 191)->nullable()->after('phone_fetch_status');
            $table->string('instagram_handle', 100)->nullable()->after('whatsapp_provider_id');
            $table->string('instagram_provider_id', 191)->nullable()->after('instagram_handle');
            $table->string('telegram_handle', 100)->nullable()->after('instagram_provider_id');
            $table->string('telegram_provider_id', 191)->nullable()->after('telegram_handle');
            $table->string('twitter_handle', 100)->nullable()->after('telegram_provider_id');
            $table->string('twitter_provider_id', 191)->nullable()->after('twitter_handle');
        });

        Schema::create('v2_lead_contact_overlays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('linkedin_key', 191);
            $table->string('email', 191)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('whatsapp_provider_id', 191)->nullable();
            $table->string('instagram_handle', 100)->nullable();
            $table->string('instagram_provider_id', 191)->nullable();
            $table->string('telegram_handle', 100)->nullable();
            $table->string('telegram_provider_id', 191)->nullable();
            $table->string('twitter_handle', 100)->nullable();
            $table->string('twitter_provider_id', 191)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'linkedin_key']);
            $table->index(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_lead_contact_overlays');

        Schema::table('sn_leads', function (Blueprint $table) {
            $table->dropColumn([
                'phone', 'phone_fetch_attempted_at', 'phone_fetch_status', 'whatsapp_provider_id',
                'instagram_handle', 'instagram_provider_id', 'telegram_handle', 'telegram_provider_id',
                'twitter_handle', 'twitter_provider_id',
            ]);
        });

        Schema::table('audience_lists', function (Blueprint $table) {
            $table->dropColumn(['con_phone', 'phone_fetch_attempted_at', 'phone_fetch_status', 'whatsapp_provider_id']);
        });
    }
};

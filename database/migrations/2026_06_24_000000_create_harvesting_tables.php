<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audiences', function (Blueprint $table) {
            $table->id();
            $table->string('audience_name')->nullable();
            $table->bigInteger('audience_id');
            $table->string('audience_type', 10)->nullable();
            $table->string('tag')->nullable();
            $table->string('source')->nullable();
            $table->json('source_meta')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
            $table->unique('audience_id');
            $table->index('user_id');
        });

        Schema::create('audience_lists', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('audience_id');
            $table->string('con_first_name')->nullable();
            $table->string('con_last_name')->nullable();
            $table->string('con_email')->nullable();
            $table->timestamp('email_fetch_attempted_at')->nullable();
            $table->string('email_fetch_status', 20)->nullable()->comment('pending, processing, completed, or NULL');
            $table->string('con_job_title')->nullable();
            $table->string('con_location')->nullable();
            $table->string('con_distance')->nullable();
            $table->string('con_public_identifier')->nullable();
            $table->string('con_id')->nullable();
            $table->string('con_tracking_id')->nullable();
            $table->tinyInteger('con_premium')->nullable();
            $table->tinyInteger('con_influencer')->nullable();
            $table->tinyInteger('con_jobseeker')->nullable();
            $table->string('con_company_url')->nullable();
            $table->string('con_company_name')->nullable();
            $table->string('con_member_urn')->nullable();
            $table->string('con_profile_url')->nullable();
            $table->dateTime('con_last_activity')->nullable();
            $table->timestamps();
            $table->index('audience_id');
        });

        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->string('oauth_provider');
            $table->string('oauth_uid');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('picture')->nullable();
            $table->text('access_token')->nullable();
            $table->text('linkedin_session_cookie')->nullable();
            $table->text('linkedin_user_agent')->nullable();
            $table->timestamp('linkedin_session_verified_at')->nullable();
            $table->text('refresh_token')->nullable();
            $table->integer('expires_in')->nullable();
            $table->integer('refresh_token_expires_in')->nullable();
            $table->boolean('connected_status')->default(false);
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
            $table->index('oauth_uid');
            $table->index('user_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->integer('daily_profile_email_scraping_count')->default(0);
            $table->date('daily_profile_email_scraping_reset_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'daily_profile_email_scraping_count',
                'daily_profile_email_scraping_reset_at',
            ]);
        });
        Schema::dropIfExists('integrations');
        Schema::dropIfExists('audience_lists');
        Schema::dropIfExists('audiences');
    }
};

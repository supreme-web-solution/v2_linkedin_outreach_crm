<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v2_integration_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 50);
            $table->string('provider_account_id', 191);
            $table->string('provider_identity_id', 191)->nullable();
            $table->string('status', 50)->default('active');
            $table->json('meta')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'provider', 'provider_account_id'], 'v2_int_acc_unique');
        });

        Schema::create('v2_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 50)->default('linkedin');
            $table->string('provider_profile_id', 191)->nullable();
            $table->string('public_identifier', 191)->nullable();
            $table->string('full_name', 191)->nullable();
            $table->string('headline', 255)->nullable();
            $table->string('company_name', 191)->nullable();
            $table->string('location', 191)->nullable();
            $table->string('email', 191)->nullable();
            $table->json('profile_data')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'provider']);
            $table->index(['public_identifier']);
        });

        Schema::create('v2_lead_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('v2_leads')->cascadeOnDelete();
            $table->string('source_type', 100);
            $table->string('source_external_id', 191)->nullable();
            $table->json('source_payload')->nullable();
            $table->timestamps();
        });

        Schema::create('v2_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 50)->default('linkedin');
            $table->string('provider_chat_id', 191)->nullable();
            $table->foreignId('lead_id')->nullable()->constrained('v2_leads')->nullOnDelete();
            $table->string('status', 50)->default('active');
            $table->timestamp('last_message_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'provider']);
        });

        Schema::create('v2_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('v2_conversations')->cascadeOnDelete();
            $table->string('provider_message_id', 191)->nullable();
            $table->string('direction', 20);
            $table->text('body')->nullable();
            $table->json('attachments')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['provider_message_id']);
        });

        Schema::create('v2_campaign_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('legacy_campaign_id')->nullable();
            $table->foreignId('lead_id')->nullable()->constrained('v2_leads')->nullOnDelete();
            $table->string('status', 50)->default('pending');
            $table->string('current_step_key', 100)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('v2_campaign_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_run_id')->constrained('v2_campaign_runs')->cascadeOnDelete();
            $table->string('step_key', 100);
            $table->string('step_type', 100);
            $table->string('status', 50)->default('pending');
            $table->timestamp('executed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });

        Schema::create('v2_provider_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 50);
            $table->string('event_type', 100);
            $table->string('event_id', 191);
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_id'], 'v2_provider_event_unique');
            $table->index(['event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_provider_events');
        Schema::dropIfExists('v2_campaign_steps');
        Schema::dropIfExists('v2_campaign_runs');
        Schema::dropIfExists('v2_messages');
        Schema::dropIfExists('v2_conversations');
        Schema::dropIfExists('v2_lead_sources');
        Schema::dropIfExists('v2_leads');
        Schema::dropIfExists('v2_integration_accounts');
    }
};

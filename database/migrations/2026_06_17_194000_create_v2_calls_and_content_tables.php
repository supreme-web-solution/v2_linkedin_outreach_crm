<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v2_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('v2_organizations')->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('v2_conversations')->nullOnDelete();
            $table->string('connection_id', 191)->nullable();
            $table->string('status', 50)->default('pending');
            $table->text('pending_message')->nullable();
            $table->json('conversation_history')->nullable();
            $table->json('ai_analysis')->nullable();
            $table->timestamp('scheduled_send_at')->nullable();
            $table->timestamp('scheduled_call_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['scheduled_send_at']);
        });

        Schema::create('v2_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('v2_organizations')->cascadeOnDelete();
            $table->foreignId('call_id')->nullable()->constrained('v2_calls')->nullOnDelete();
            $table->string('status', 50)->default('pending');
            $table->text('message')->nullable();
            $table->timestamp('send_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status', 'send_at']);
        });

        Schema::create('v2_call_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('v2_organizations')->cascadeOnDelete();
            $table->string('name', 191);
            $table->string('status', 50)->default('active');
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('v2_call_campaign_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('v2_call_campaigns')->cascadeOnDelete();
            $table->string('recipient_id', 191);
            $table->text('message');
            $table->string('status', 50)->default('pending');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
        });

        Schema::create('v2_content_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('v2_organizations')->cascadeOnDelete();
            $table->string('provider', 50)->default('linkedin');
            $table->text('content');
            $table->string('status', 50)->default('draft');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('v2_inspiration_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('v2_organizations')->cascadeOnDelete();
            $table->string('source', 50)->default('linkedin');
            $table->string('post_id', 191)->nullable();
            $table->text('content')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('v2_esp_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('v2_organizations')->cascadeOnDelete();
            $table->string('provider', 100);
            $table->json('config')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'provider'], 'v2_esp_org_provider_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_esp_integrations');
        Schema::dropIfExists('v2_inspiration_posts');
        Schema::dropIfExists('v2_content_posts');
        Schema::dropIfExists('v2_call_campaign_messages');
        Schema::dropIfExists('v2_call_campaigns');
        Schema::dropIfExists('v2_reminders');
        Schema::dropIfExists('v2_calls');
    }
};

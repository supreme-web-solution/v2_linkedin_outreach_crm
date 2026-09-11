<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_employee_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('v2_organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('enabled')->default(true);
            $table->boolean('kill_switch')->default(false);
            $table->unsignedTinyInteger('autonomy_level')->default(2);
            $table->string('employee_name')->default('Soci');
            $table->json('allowed_execute_tools')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'user_id']);
        });

        Schema::create('ai_channel_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('v2_organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('channel', 32);
            $table->string('external_id', 64);
            $table->string('status', 32)->default('active');
            $table->timestamp('verified_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['channel', 'external_id']);
            $table->index(['organization_id', 'user_id']);
        });

        Schema::create('ai_channel_link_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('v2_organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('channel', 32)->default('whatsapp');
            $table->string('code', 32);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->unique('code');
            $table->index(['channel', 'code']);
        });

        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('v2_organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('channel', 32)->default('web');
            $table->foreignId('channel_identity_id')->nullable()->constrained('ai_channel_identities')->nullOnDelete();
            $table->string('title')->nullable();
            $table->string('status', 32)->default('open');
            $table->string('laravel_ai_conversation_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'user_id', 'channel', 'status']);
        });

        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->string('role', 32);
            $table->longText('content');
            $table->string('provider_message_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
            $table->index(['provider_message_id']);
        });

        Schema::create('ai_action_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('v2_organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('ai_conversations')->nullOnDelete();
            $table->string('tool');
            $table->string('permission', 32);
            $table->json('payload');
            $table->string('status', 32)->default('pending');
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('result')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'user_id', 'status']);
        });

        Schema::create('ai_action_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('v2_organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('ai_conversations')->nullOnDelete();
            $table->string('tool');
            $table->string('permission', 32);
            $table->string('status', 32);
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_action_logs');
        Schema::dropIfExists('ai_action_approvals');
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
        Schema::dropIfExists('ai_channel_link_codes');
        Schema::dropIfExists('ai_channel_identities');
        Schema::dropIfExists('ai_employee_settings');
    }
};

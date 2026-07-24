<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v2_team_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('v2_organizations')->cascadeOnDelete();
            $table->foreignId('inviter_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('invitee_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('invitee_email', 191);
            $table->string('role', 50)->default('member');
            $table->json('capabilities')->nullable();
            $table->string('status', 50)->default('pending');
            $table->string('token', 120)->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['invitee_email']);
        });

        Schema::create('v2_esp_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('v2_organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('esp_integration_id')->nullable()->constrained('v2_esp_integrations')->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('v2_leads')->nullOnDelete();
            $table->string('provider', 100);
            $table->string('recipient', 191)->nullable();
            $table->string('status', 50)->default('queued');
            $table->string('external_message_id', 191)->nullable();
            $table->text('subject')->nullable();
            $table->text('body_preview')->nullable();
            $table->json('events')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamp('bounced_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['provider', 'external_message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_esp_deliveries');
        Schema::dropIfExists('v2_team_invites');
    }
};

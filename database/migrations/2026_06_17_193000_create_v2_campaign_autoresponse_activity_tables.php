<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v2_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('v2_organizations')->cascadeOnDelete();
            $table->string('name', 191);
            $table->string('sequence_type', 50)->default('lead_gen');
            $table->string('status', 50)->default('active');
            $table->json('node_model')->nullable();
            $table->json('link_model')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });

        Schema::create('v2_auto_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('v2_organizations')->cascadeOnDelete();
            $table->string('message_type', 50)->default('contains');
            $table->string('message_keywords', 255)->nullable();
            $table->text('message_body');
            $table->json('attachments')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['organization_id', 'enabled']);
        });

        Schema::create('v2_mini_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('v2_organizations')->cascadeOnDelete();
            $table->integer('connections')->default(0);
            $table->integer('sent_invites')->default(0);
            $table->integer('profile_views')->default(0);
            $table->string('profile_id', 191)->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
        });

        Schema::create('v2_user_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('v2_organizations')->cascadeOnDelete();
            $table->string('module', 100);
            $table->integer('stat')->default(0);
            $table->string('identifier', 191)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'module', 'created_at'], 'v2_user_act_org_mod_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_user_activities');
        Schema::dropIfExists('v2_mini_stats');
        Schema::dropIfExists('v2_auto_responses');
        Schema::dropIfExists('v2_campaigns');
    }
};

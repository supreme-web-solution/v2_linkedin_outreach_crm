<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Links leads/profiles to a campaign (the audience)
        Schema::create('v2_campaign_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('v2_campaigns')->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('v2_leads')->nullOnDelete();
            $table->string('provider_profile_id', 191)->nullable();
            $table->string('full_name', 191)->nullable();
            $table->string('headline', 255)->nullable();
            $table->string('profile_url', 512)->nullable();
            $table->string('status', 50)->default('pending'); // pending, running, done, skipped, error
            $table->timestamps();

            $table->index(['campaign_id', 'status']);
        });

        // Per-lead execution progress through campaign steps
        Schema::create('v2_campaign_lead_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('v2_campaigns')->cascadeOnDelete();
            $table->foreignId('campaign_lead_id')->constrained('v2_campaign_leads')->cascadeOnDelete();
            $table->unsignedInteger('current_node_key')->default(0);
            $table->unsignedInteger('next_node_key')->default(1);
            // acceptance_status: null=pending, true=accepted, false=not_accepted
            $table->boolean('acceptance_status')->nullable();
            // run_status: 0=initial, 1=invite_sent, 2=accepted, 3=messaging, 4=done, 9=error
            $table->unsignedTinyInteger('run_status')->default(0);
            $table->json('completed_keys')->nullable();   // array of node keys already executed
            $table->json('meta')->nullable();
            $table->timestamp('next_run_at')->nullable(); // when to run the next step (after delay)
            $table->timestamps();

            $table->unique(['campaign_id', 'campaign_lead_id']);
            $table->index(['campaign_id', 'run_status', 'next_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_campaign_lead_progress');
        Schema::dropIfExists('v2_campaign_leads');
    }
};

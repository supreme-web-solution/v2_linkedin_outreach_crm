<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v2_outreach_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->string('name', 191);
            $table->string('template_type', 50)->default('custom');
            $table->string('status', 50)->default('draft');
            $table->json('node_model')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });

        Schema::create('v2_outreach_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outreach_campaign_id')->constrained('v2_outreach_campaigns')->cascadeOnDelete();
            $table->string('list_hash', 50);
            $table->string('list_src', 10);
            $table->string('list_name')->nullable();
            $table->timestamps();

            $table->unique(['outreach_campaign_id', 'list_hash', 'list_src'], 'v2_outreach_lists_unique');
        });

        Schema::create('v2_outreach_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outreach_campaign_id')->constrained('v2_outreach_campaigns')->cascadeOnDelete();
            $table->string('source_list_src', 10)->nullable();
            $table->unsignedBigInteger('source_record_id')->nullable();
            $table->string('provider_profile_id', 191)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('full_name', 191)->nullable();
            $table->string('headline', 255)->nullable();
            $table->string('profile_url', 512)->nullable();
            $table->string('status', 50)->default('pending');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['outreach_campaign_id', 'source_list_src', 'source_record_id'], 'v2_outreach_leads_source_unique');
            $table->index(['outreach_campaign_id', 'status'], 'v2_oleads_campaign_status_idx');
        });

        Schema::create('v2_outreach_lead_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outreach_campaign_id')->constrained('v2_outreach_campaigns')->cascadeOnDelete();
            $table->foreignId('outreach_lead_id')->constrained('v2_outreach_leads')->cascadeOnDelete();
            $table->unsignedInteger('current_node_key')->default(0);
            $table->unsignedInteger('next_node_key')->default(1);
            $table->boolean('acceptance_status')->nullable();
            $table->unsignedTinyInteger('run_status')->default(0);
            $table->json('completed_keys')->nullable();
            $table->json('channel_state')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();

            $table->unique(['outreach_campaign_id', 'outreach_lead_id'], 'v2_outreach_progress_unique');
            $table->index(['outreach_campaign_id', 'run_status', 'next_run_at'], 'v2_olp_run_next_idx');
        });

        Schema::create('v2_outreach_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outreach_campaign_id')->constrained('v2_outreach_campaigns')->cascadeOnDelete();
            $table->string('status', 50)->default('queued');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('v2_outreach_node_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outreach_campaign_id')->constrained('v2_outreach_campaigns')->cascadeOnDelete();
            $table->foreignId('outreach_lead_id')->nullable()->constrained('v2_outreach_leads')->nullOnDelete();
            $table->foreignId('outreach_run_id')->nullable()->constrained('v2_outreach_runs')->nullOnDelete();
            $table->unsignedInteger('node_key')->nullable();
            $table->string('channel', 50)->nullable();
            $table->string('action', 50)->nullable();
            $table->string('status', 50);
            $table->string('message', 512);
            $table->json('payload')->nullable();
            $table->timestamp('executed_at');
            $table->timestamps();

            $table->index(['outreach_campaign_id', 'executed_at'], 'v2_one_campaign_exec_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_outreach_node_events');
        Schema::dropIfExists('v2_outreach_runs');
        Schema::dropIfExists('v2_outreach_lead_progress');
        Schema::dropIfExists('v2_outreach_leads');
        Schema::dropIfExists('v2_outreach_lists');
        Schema::dropIfExists('v2_outreach_campaigns');
    }
};

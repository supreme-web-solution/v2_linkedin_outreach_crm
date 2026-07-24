<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v2_campaign_node_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('campaign_id');
            $table->unsignedBigInteger('campaign_lead_id')->nullable();
            $table->unsignedBigInteger('campaign_run_id')->nullable();
            $table->unsignedInteger('node_key')->nullable();
            $table->string('node_label')->nullable();
            $table->string('step_type', 64)->nullable();
            $table->string('status', 32)->default('info');
            $table->text('message')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('executed_at')->useCurrent();
            $table->timestamps();

            $table->index(['campaign_id', 'executed_at']);
            $table->index(['campaign_lead_id', 'executed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_campaign_node_events');
    }
};

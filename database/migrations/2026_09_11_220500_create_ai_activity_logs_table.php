<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('v2_organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('ai_conversations')->nullOnDelete();
            $table->string('tool');
            $table->string('action');
            $table->string('entity_type')->nullable();
            $table->string('entity_id')->nullable();
            $table->json('payload')->nullable();
            $table->text('trigger_text')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'user_id', 'created_at']);
            $table->index(['organization_id', 'action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_activity_logs');
    }
};

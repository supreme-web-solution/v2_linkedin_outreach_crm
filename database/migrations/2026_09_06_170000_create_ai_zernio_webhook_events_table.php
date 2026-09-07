<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_zernio_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('event', 64);
            $table->string('status', 32)->default('processed');
            $table->json('meta')->nullable();
            $table->timestamp('processed_at')->useCurrent();
            $table->timestamps();

            $table->index(['event', 'processed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_zernio_webhook_events');
    }
};

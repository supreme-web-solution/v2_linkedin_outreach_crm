<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v2_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 120);
            $table->string('key_hash', 255);
            $table->timestamps();

            $table->unique(['user_id', 'scope', 'key_hash'], 'v2_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_idempotency_keys');
    }
};

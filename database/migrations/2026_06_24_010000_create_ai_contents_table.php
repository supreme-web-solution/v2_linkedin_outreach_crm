<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_contents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('contents')->nullable();
            $table->integer('word_counts')->default(0);
            $table->string('ai_type', 50);
            $table->string('language', 50)->default('English');
            $table->string('idea')->nullable();
            $table->string('write_style', 100)->nullable();
            $table->string('connection_message_type')->nullable();
            $table->string('connection_message_location')->nullable();
            $table->string('connection_message_industry')->nullable();
            $table->string('connection_message_jobtitle')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_contents');
    }
};

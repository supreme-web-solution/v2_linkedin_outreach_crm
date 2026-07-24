<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_inspiration_posts', function (Blueprint $table) {
            $table->boolean('is_favorite')->default(false)->after('content');
            $table->string('category', 50)->nullable()->after('is_favorite');
            $table->unsignedInteger('engagement')->default(0)->after('category');
            $table->index(['organization_id', 'is_favorite']);
        });
    }

    public function down(): void
    {
        Schema::table('v2_inspiration_posts', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'is_favorite']);
            $table->dropColumn(['is_favorite', 'category', 'engagement']);
        });
    }
};

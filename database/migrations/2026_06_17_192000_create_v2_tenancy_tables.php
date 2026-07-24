<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v2_organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 191);
            $table->string('slug', 191)->unique();
            $table->string('status', 50)->default('active');
            $table->timestamps();
        });

        Schema::create('v2_organization_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('v2_organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 50)->default('member');
            $table->json('capabilities')->nullable();
            $table->string('status', 50)->default('active');
            $table->timestamps();

            $table->unique(['organization_id', 'user_id'], 'v2_org_user_unique');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('current_organization_id')->nullable()->after('remember_token');
            $table->index(['current_organization_id']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['current_organization_id']);
            $table->dropColumn('current_organization_id');
        });

        Schema::dropIfExists('v2_organization_user');
        Schema::dropIfExists('v2_organizations');
    }
};

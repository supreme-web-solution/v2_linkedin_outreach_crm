<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sn_leads_lists', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            // String keys (e.g. search-1-audience-name); legacy installs may still be bigint until alter migration.
            $table->string('list_hash', 64);
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
            $table->index('user_id');
            $table->index('list_hash');
        });

        Schema::create('sn_leads', function (Blueprint $table) {
            $table->id();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('headline')->nullable();
            $table->string('email')->nullable();
            $table->string('lid')->nullable();
            $table->string('sn_lid')->nullable();
            $table->text('picture')->nullable();
            $table->text('geolocation')->nullable();
            $table->string('degree', 15)->nullable();
            $table->string('object_urn', 50)->nullable();
            $table->text('jobs')->nullable();
            $table->string('sn_list_id', 64);
            $table->timestamps();
            $table->index('lid');
            $table->index('sn_list_id');
        });

        Schema::create('sn_leads_companies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sn_lead_id');
            $table->string('company_name')->nullable();
            $table->string('company_specialities')->nullable();
            $table->string('company_tagline')->nullable();
            $table->text('company_description')->nullable();
            $table->string('company_website')->nullable();
            $table->string('company_phone', 15)->nullable();
            $table->string('company_staff_range')->nullable();
            $table->string('company_staff_count')->nullable();
            $table->string('company_headquaters')->nullable();
            $table->string('company_revenue')->nullable();
            $table->text('company_industries')->nullable();
            $table->text('company_logo')->nullable();
            $table->string('company_founded')->nullable();
            $table->string('company_lid')->nullable();
            $table->timestamps();
            $table->index('sn_lead_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sn_leads_companies');
        Schema::dropIfExists('sn_leads');
        Schema::dropIfExists('sn_leads_lists');
    }
};

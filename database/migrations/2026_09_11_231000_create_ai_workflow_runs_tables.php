<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_workflow_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('v2_organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('ai_conversations')->nullOnDelete();
            $table->string('agent', 120)->default('SociFusionAgent');
            $table->string('goal', 120)->nullable();
            $table->string('status', 40)->default('planned');
            $table->json('plan')->nullable();
            $table->string('current_step', 120)->nullable();
            $table->string('approval_status', 40)->default('pending');
            $table->json('approval_scope')->nullable();
            $table->string('scope_hash', 64)->nullable()->index();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_workflow_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workflow_run_id')->constrained('ai_workflow_runs')->cascadeOnDelete();
            $table->string('step_key', 120);
            $table->unsignedInteger('sequence')->default(1);
            $table->string('tool_name', 120)->nullable();
            $table->json('arguments')->nullable();
            $table->string('status', 40)->default('pending');
            $table->json('depends_on')->nullable();
            $table->boolean('approval_required')->default(false);
            $table->string('approval_status', 40)->default('not_required');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->timestamps();

            $table->index(['workflow_run_id', 'sequence']);
        });

        Schema::table('ai_action_approvals', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_action_approvals', 'workflow_run_id')) {
                $table->foreignId('workflow_run_id')->nullable()->after('conversation_id')->constrained('ai_workflow_runs')->nullOnDelete();
            }
            if (! Schema::hasColumn('ai_action_approvals', 'scope_hash')) {
                $table->string('scope_hash', 64)->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_action_approvals', function (Blueprint $table): void {
            if (Schema::hasColumn('ai_action_approvals', 'workflow_run_id')) {
                $table->dropConstrainedForeignId('workflow_run_id');
            }
            if (Schema::hasColumn('ai_action_approvals', 'scope_hash')) {
                $table->dropColumn('scope_hash');
            }
        });

        Schema::dropIfExists('ai_workflow_steps');
        Schema::dropIfExists('ai_workflow_runs');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('goal_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feature_spec_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_manager_agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->foreignId('backend_engineer_agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->foreignId('reviewer_agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->foreignId('project_manager_agent_run_id')->nullable()->constrained('agent_runs')->nullOnDelete();
            $table->text('goal_text')->nullable();
            $table->json('contract')->nullable();
            $table->json('pm_output')->nullable();
            $table->json('configuration_snapshot')->nullable();
            $table->string('native_definition_hash', 64)->nullable();
            $table->string('harness')->nullable();
            $table->string('model')->nullable();
            $table->string('approval_mode')->default('required');
            $table->string('status')->default('planning')->index();
            $table->unsignedInteger('version')->default(1);
            $table->string('context_hash', 64)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique('feature_spec_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('goal_runs');
    }
};

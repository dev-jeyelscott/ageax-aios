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
        Schema::create('recovery_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agent_worker_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('source_agent_run_id')->nullable()->constrained('agent_runs')->nullOnDelete();
            $table->string('failure_type');
            $table->string('status')->index();
            $table->timestamp('detected_at');
            $table->json('evidence')->nullable();
            $table->text('root_cause')->nullable();
            $table->string('root_cause_category')->nullable();
            $table->boolean('recoverable')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->text('fix_summary')->nullable();
            $table->json('validation_evidence')->nullable();
            $table->string('resulting_task_transition')->nullable();
            $table->text('escalation_reason')->nullable();
            $table->string('base_sha')->nullable();
            $table->string('head_sha')->nullable();
            $table->string('commit_sha')->nullable();
            $table->json('changed_files')->nullable();
            $table->uuid('claim_token')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['task_id', 'status']);
            $table->index(['project_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recovery_incidents');
    }
};

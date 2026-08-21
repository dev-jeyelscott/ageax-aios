<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_planning_escalations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_task_attempt_id')->nullable()->constrained('task_attempts')->nullOnDelete();
            $table->string('defect_type');
            $table->string('fingerprint', 64);
            $table->json('failure_evidence');
            $table->json('allowed_fields');
            $table->string('status')->index();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        DB::statement("create unique index task_planning_escalations_one_active_per_task on task_planning_escalations (task_id) where status in ('pending', 'running')");

        Schema::create('task_planning_revision_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_planning_escalation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_run_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('number');
            $table->string('status')->index();
            $table->json('proposal')->nullable();
            $table->timestamp('claimed_at')->index();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['task_planning_escalation_id', 'number']);
        });
    }

    public function down(): void
    {
        DB::statement('drop index if exists task_planning_escalations_one_active_per_task');
        Schema::dropIfExists('task_planning_revision_attempts');
        Schema::dropIfExists('task_planning_escalations');
    }
};

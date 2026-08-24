<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create durable typed Agent handoff evidence.
     */
    public function up(): void
    {
        Schema::create('agent_handoffs', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('project_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('task_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('from_agent_run_id')
                ->constrained('agent_runs')
                ->cascadeOnDelete();

            $table->string('from_role', 64);
            $table->string('to_role', 64);
            $table->string('handoff_type', 64);
            $table->unsignedSmallInteger('schema_version');
            $table->json('payload');
            $table->char('content_hash', 64);
            $table->string('status', 32)->default('pending');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('consumed_at')->nullable();

            $table->unique(
                [
                    'project_id',
                    'content_hash',
                ],
                'agent_handoffs_project_hash_unique',
            );

            $table->index(
                ['from_agent_run_id'],
                'agent_handoffs_source_run_idx',
            );

            $table->index(
                [
                    'project_id',
                    'task_id',
                    'status',
                ],
                'agent_handoffs_project_task_status_idx',
            );

            $table->index(
                [
                    'project_id',
                    'to_role',
                    'status',
                ],
                'agent_handoffs_target_status_idx',
            );
        });
    }

    /**
     * Remove only the additive P8-001 handoff persistence table.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_handoffs');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create immutable advisory recommendation evidence linked to an AgentRun.
     */
    public function up(): void
    {
        Schema::create('orchestration_recommendations', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('project_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('task_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('recovery_incident_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('agent_run_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('recommendation_type', 64);
            $table->unsignedSmallInteger('schema_version');
            $table->char('evidence_hash', 64);
            $table->decimal('confidence', 5, 4);
            $table->json('structured_recommendation');
            $table->string('status', 32)->default('active');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                ['agent_run_id', 'recommendation_type'],
                'orchestration_recs_run_type_unique',
            );

            $table->index(
                ['project_id', 'status'],
                'orchestration_recs_project_status_idx',
            );

            $table->index(
                ['recommendation_type', 'status'],
                'orchestration_recs_type_status_idx',
            );
        });
    }

    /**
     * Remove only the additive P5-002 recommendation table.
     */
    public function down(): void
    {
        Schema::dropIfExists('orchestration_recommendations');
    }
};

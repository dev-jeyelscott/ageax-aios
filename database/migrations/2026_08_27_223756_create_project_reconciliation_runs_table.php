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
        Schema::create('project_reconciliation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('agent_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('trigger');
            $table->string('status')->index();
            $table->string('baseline_sha')->nullable();
            $table->string('evaluated_head_sha')->nullable();
            $table->string('snapshot_hash', 64)->nullable();
            $table->boolean('working_tree_dirty')->default(false);
            $table->json('result')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_reconciliation_runs');
    }
};

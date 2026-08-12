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
        Schema::create('agent_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agent_worker_id')->nullable()->constrained()->nullOnDelete();
            $table->string('role');
            $table->string('status')->index();
            $table->unsignedInteger('attempt_number')->nullable();
            $table->string('codex_run_id')->nullable()->index();
            $table->string('prompt_hash', 64);
            $table->json('result')->nullable();
            $table->json('commands')->nullable();
            $table->json('file_modifications')->nullable();
            $table->unsignedBigInteger('token_usage')->nullable();
            $table->string('log_path')->nullable();
            $table->integer('exit_code')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_runs');
    }
};

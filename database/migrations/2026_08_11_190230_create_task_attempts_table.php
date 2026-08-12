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
        Schema::create('task_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('base_sha', 64)->nullable();
            $table->string('head_sha', 64)->nullable();
            $table->string('commit_sha', 64)->nullable();
            $table->string('status')->index();
            $table->json('validation_results')->nullable();
            $table->json('changed_files')->nullable();
            $table->string('log_path')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unique(['task_id', 'number']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_attempts');
    }
};

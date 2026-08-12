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
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('phase_id')->nullable()->constrained()->nullOnDelete();
            $table->string('key');
            $table->unsignedInteger('position');
            $table->string('title');
            $table->text('objective');
            $table->json('acceptance_criteria');
            $table->json('scope')->nullable();
            $table->json('constraints')->nullable();
            $table->json('relevant_paths')->nullable();
            $table->json('verification_commands')->nullable();
            $table->longText('implementation_prompt');
            $table->json('context_capsule');
            $table->string('status')->default('queued')->index();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unique(['project_id', 'key']);
            $table->unique(['project_id', 'position']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};

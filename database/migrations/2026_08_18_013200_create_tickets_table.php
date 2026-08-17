<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('submitted_by_user_id')->constrained('users');
            $table->string('key', 32);
            $table->string('title');
            $table->text('description');
            $table->string('requester_category', 32)->nullable();
            $table->string('category', 32)->nullable();
            $table->string('status', 32)->default('open');
            $table->string('decision', 32)->nullable();
            $table->string('requester_urgency', 32)->nullable();
            $table->string('ai_suggested_priority', 32)->nullable();
            $table->string('final_priority', 32)->nullable();
            $table->decimal('triage_confidence', 4, 3)->nullable();
            $table->foreignId('converted_task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->timestamp('awaiting_response_until')->nullable();
            $table->timestamp('triaged_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'key']);
            $table->index(['project_id', 'status']);
            $table->index(['project_id', 'category']);
            $table->index(['project_id', 'final_priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};

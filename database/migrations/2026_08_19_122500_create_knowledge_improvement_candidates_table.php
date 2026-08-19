<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_improvement_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('target_skill_id')->nullable()->constrained('skills')->nullOnDelete();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->char('fingerprint', 64);
            $table->string('source_kind', 64);
            $table->string('failure_code');
            $table->string('affected_role')->nullable();
            $table->string('affected_area')->nullable();
            $table->string('status')->default('pending')->index();
            $table->string('target_type');
            $table->text('evidence_summary');
            $table->text('proposed_change');
            $table->json('evidence');
            $table->unsignedInteger('occurrence_count');
            $table->unsignedInteger('reopen_after_occurrence')->nullable();
            $table->char('evidence_hash', 64);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->unsignedInteger('applied_skill_version')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'fingerprint']);
            $table->index(['project_id', 'status']);
            $table->index(['project_id', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_improvement_candidates');
    }
};

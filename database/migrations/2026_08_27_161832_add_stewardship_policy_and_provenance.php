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
        Schema::table('projects', function (Blueprint $table): void {
            $table->json('stewardship_policy')->nullable()->after('roadmap_scanned_at');
        });

        Schema::table('knowledge_improvement_candidates', function (Blueprint $table): void {
            $table->foreignId('source_reconciliation_run_id')->nullable()->after('project_id')->constrained('project_reconciliation_runs')->nullOnDelete();
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->foreignId('knowledge_improvement_candidate_id')->nullable()->after('project_id')->constrained()->nullOnDelete();
            $table->json('stewardship_provenance')->nullable()->after('context_capsule');
            $table->unique('knowledge_improvement_candidate_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropUnique(['knowledge_improvement_candidate_id']);
            $table->dropConstrainedForeignId('knowledge_improvement_candidate_id');
            $table->dropColumn('stewardship_provenance');
        });

        Schema::table('knowledge_improvement_candidates', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_reconciliation_run_id');
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('stewardship_policy');
        });
    }
};

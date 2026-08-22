<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add provenance for the latest semantic advisory tied to deterministic candidate evidence.
     */
    public function up(): void
    {
        Schema::table('knowledge_improvement_candidates', function (Blueprint $table): void {
            $table
                ->foreignId('knowledge_architect_agent_run_id')
                ->nullable()
                ->after('evidence_hash')
                ->constrained('agent_runs')
                ->nullOnDelete();

            $table
                ->char('knowledge_architect_evidence_hash', 64)
                ->nullable()
                ->after('knowledge_architect_agent_run_id');
        });
    }

    /**
     * Remove only the Knowledge Architect provenance fields.
     */
    public function down(): void
    {
        Schema::table('knowledge_improvement_candidates', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('knowledge_architect_agent_run_id');
            $table->dropColumn('knowledge_architect_evidence_hash');
        });
    }
};

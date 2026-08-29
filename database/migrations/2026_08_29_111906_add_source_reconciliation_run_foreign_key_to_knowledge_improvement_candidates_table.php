<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_improvement_candidates', function (Blueprint $table): void {
            $table->foreign('source_reconciliation_run_id')
                ->references('id')
                ->on('project_reconciliation_runs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_improvement_candidates', function (Blueprint $table): void {
            $table->dropForeign(['source_reconciliation_run_id']);
        });
    }
};

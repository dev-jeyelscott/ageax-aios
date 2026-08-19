<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_runs', function (Blueprint $table): void {
            $table->json('context_budget_snapshot')->nullable()->after('context_cost_schema_version');
            $table->unsignedSmallInteger('context_budget_schema_version')->nullable()->after('context_budget_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table): void {
            $table->dropColumn([
                'context_budget_snapshot',
                'context_budget_schema_version',
            ]);
        });
    }
};


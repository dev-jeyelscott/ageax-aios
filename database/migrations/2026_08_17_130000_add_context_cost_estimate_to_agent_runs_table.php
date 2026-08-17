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
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->json('context_cost_estimate')->nullable()->after('context_schema_version');
            $table->unsignedInteger('context_cost_schema_version')->nullable()->after('context_cost_estimate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropColumn(['context_cost_estimate', 'context_cost_schema_version']);
        });
    }
};

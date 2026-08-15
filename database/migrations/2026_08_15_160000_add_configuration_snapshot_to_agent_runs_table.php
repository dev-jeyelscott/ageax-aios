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
            $table->foreignId('agent_id')->nullable()->after('agent_worker_id')->constrained()->nullOnDelete();
            $table->string('harness')->nullable()->after('role');
            $table->string('external_run_id')->nullable()->after('codex_run_id')->index();
            $table->json('configuration_snapshot')->nullable()->after('result');
            $table->unsignedInteger('context_schema_version')->nullable()->after('configuration_snapshot');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agent_id');
            $table->dropColumn(['harness', 'external_run_id', 'configuration_snapshot', 'context_schema_version']);
        });
    }
};

<?php

use App\AgentRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Provision the singleton global advisory Orchestrator when it does not already exist.
     */
    public function up(): void
    {
        $exists = DB::table('agents')
            ->whereNull('project_id')
            ->where('role', AgentRole::Orchestrator->value)
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('agents')->insert([
            'project_id' => null,
            'name' => 'AIOS Global Orchestrator',
            'role' => AgentRole::Orchestrator->value,
            'harness' => config('aios.orchestrator_harness'),
            'model' => config('aios.orchestrator_model'),
            'reasoning_setting' => config('aios.orchestrator_reasoning_setting'),
            'default_context' => null,
            'enabled' => true,
            'configuration_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Preserve the global Orchestrator because historical AgentRun evidence may reference it.
     */
    public function down(): void
    {
        // Intentionally preserve the Agent row and its historical configuration.
    }
};

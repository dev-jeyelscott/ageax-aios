<?php

use App\AgentHarness;
use App\AgentRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Provision the singleton global advisory Knowledge Architect when it does not already exist.
     */
    public function up(): void
    {
        $exists = DB::table('agents')
            ->whereNull('project_id')
            ->where('role', AgentRole::KnowledgeArchitect->value)
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('agents')->insert([
            'project_id' => null,
            'name' => 'AIOS Knowledge Architect',
            'role' => AgentRole::KnowledgeArchitect->value,
            'harness' => AgentHarness::ClaudeCode->value,
            'model' => null,
            'reasoning_setting' => null,
            'default_context' => implode(' ', [
                'Analyze only bounded deterministic knowledge evidence supplied by AIOS.',
                'Return advisory proposals only.',
                'Never mutate documentation, Skills, rules, Agent configuration, Git state,',
                'workflow state, source manifests, or any authoritative knowledge source.',
            ]),
            'enabled' => true,
            'configuration_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Preserve the global Agent because historical AgentRun evidence may reference this identity.
     */
    public function down(): void
    {
        // Intentionally preserve historical global Agent identity.
    }
};

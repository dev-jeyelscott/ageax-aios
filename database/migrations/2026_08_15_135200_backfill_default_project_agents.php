<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<array{name: string, role: string}> */
    private const array DEFAULT_AGENTS = [
        ['name' => 'Project Manager', 'role' => 'project_manager'],
        ['name' => 'Coder', 'role' => 'coder'],
        ['name' => 'Reviewer', 'role' => 'reviewer'],
    ];

    public function up(): void
    {
        DB::table('projects')
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($projects): void {
                $timestamp = now();
                $rows = [];

                foreach ($projects as $project) {
                    foreach (self::DEFAULT_AGENTS as $definition) {
                        $rows[] = [
                            'project_id' => $project->id,
                            'name' => $definition['name'],
                            'role' => $definition['role'],
                            'harness' => 'codex',
                            'model' => null,
                            'reasoning_setting' => null,
                            'default_context' => null,
                            'enabled' => true,
                            'configuration_version' => 1,
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ];
                    }
                }

                if ($rows !== []) {
                    DB::table('agents')->insertOrIgnore($rows);
                }
            });
    }

    /**
     * Historical Agent configuration is intentionally preserved on rollback.
     */
    public function down(): void {}
};
